<?php

namespace Drupal\tus;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use TusPhp\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use TusPhp\Events\TusEvent;
use TusPhp\Tus\Server;
use Drupal\file\Entity\File;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Class TusServer.
 */
class TusServer implements TusServerInterface, ContainerInjectionInterface {

  /**
   * Instance of entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Instance of Token.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Instance of FileUsage.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * Instance of EntityTypeManager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Instance of FileSystem.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Tus settings config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Tus cache directory URI.
   *
   * @var string
   */
  protected $tusCacheDir;

  /**
   * Constructs a new TusServer object.
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager, Token $token, FileUsageInterface $file_usage, EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, ConfigFactoryInterface $config_factory) {
    $this->entityFieldManager = $entity_field_manager;
    $this->token = $token;
    $this->fileUsage = $file_usage;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->config = $config_factory->get('tus.settings');
    $this->tusCacheDir = $this->config->get('cache_dir');

    if (!$this->fileSystem->prepareDirectory($this->tusCacheDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, sprintf('TUS cache folder "%s" is not writable.', $this->tusCacheDir));
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_field.manager'),
      $container->get('token'),
      $container->get('file.usage'),
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getServer(string $upload_key = '', array $post_data = []): Server {
    $server = $this->getTusServer($upload_key);

    // These methods won't pass metadata about the file, and don't need
    // file directory, because they are reading from TUS cache, so we
    // can return the server now.
    $request_method = strtoupper($server->getRequest()->method());
    if (in_array($request_method, ['GET', 'HEAD', 'PATCH'], TRUE)) {
      return $server;
    }

    // Get upload key for directory creation. On POST, it isn't passed, but
    // we need to add the UUID to file directory to ensure we don't
    // concatenate same-file uploads if client key is lost.
    if ($request_method === 'POST') {
      $upload_key = $server->getUploadKey();
    }

    // Get the file destination.
    $destination = $this->determineDestination($upload_key, $post_data);
    // Set the upload directory for TUS.
    $server->setUploadDir($destination);

    return $server;
  }

  /**
   * Get a configured instance of Tus Server.
   *
   * @param string $upload_key
   *   Upload key from tus if available.
   *
   * @return \TusPhp\Tus\Server
   *   Configured instance of Tus server
   *
   * @throws \ReflectionException
   */
  protected function getTusServer(string $upload_key = ''): Server {
    // Set TUS config cache directory.
    Config::set([
      'file' => [
        'dir' => $this->fileSystem->realpath($this->tusCacheDir) . '/',
        // Prefix the file with a '.' for added security as most web servers
        // won't grant access to files beginning with a dot.
        'name' => '.tus.cache',
      ],
    ]);

    // Initialize TUS server.
    $server = new Server();
    $server->setApiPath('/tus/upload');

    if ($upload_key) {
      $server->setUploadKey($upload_key);
    }

    $server->event()->addListener('tus-server.upload.complete', [$this, 'uploadComplete']);

    return $server;
  }

  /**
   * Determine Drupal URI.
   *
   * Determine the Drupal URI for a file based on TUS upload key and meta params
   * from the upload client.
   *
   * @param string $upload_key
   *   The TUS upload key.
   * @param array $field_info
   *   Params about the entity type, bundle, and field_name.
   *
   * @return string
   *   The intended destination uri for the file.
   */
  protected function determineDestination(string $upload_key, array $field_info): string {
    // If fieldInfo was not passed, we cannot determine file path.
    if (empty($field_info)) {
      throw new HttpException(500, 'Destination file path unknown because field info not sent in client meta.');
    }

    $destination = '';
    $bundle_fields = $this->entityFieldManager
      ->getFieldDefinitions($field_info['entityType'], $field_info['entityBundle']);
    $field_definition = $bundle_fields[$field_info['fieldName']];

    // Get the field's configured destination directory.
    $file_path = trim($this->token->replace($field_definition->getSetting('file_directory')), '/');
    $destination = $field_definition->getSetting('uri_scheme') . '://' . $file_path . '/' . $upload_key;

    // Ensure directory creation.
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, 'Destination file path:' . $destination . ' is not writable.');
    }

    return $destination;
  }

  /**
   * {@inheritdoc}
   */
  public function uploadComplete(TusEvent $event): void {
    $tus_file = $event->getFile();
    $file_name = $tus_file->getName();
    $file_path = $tus_file->getFilePath();
    $metadata = $tus_file->details()['metadata'];

    // Double check the uploaded file is supported for this field.
    $bundle_fields = $this->entityFieldManager
      ->getFieldDefinitions($metadata['entityType'], $metadata['entityBundle']);
    $field_definition = $bundle_fields[$metadata['fieldName']];

    // Check the uploaded file type is permitted by field.
    $allowed_extensions = explode(' ', $field_definition->getSettings()['file_extensions']);
    $file_type = end(explode('/', $metadata['filetype']));

    if (!in_array($file_type, $allowed_extensions, TRUE)) {
      throw new UnprocessableEntityHttpException(sprintf('File type "%s" is not supported for this field.', $file_type));
    }

    // Check if the file already exists.
    $file_query = $this->entityTypeManager->getStorage('file')->getQuery();
    $file_query->condition('uri', $file_path);
    $results = $file_query->execute();

    if (!empty($results)) {
      // File already exists, just add usage.
      $file = reset($results);
      $this->fileUsage->add($file, 'tus', 'file', $file->id());
      return;
    }

    $file = File::create([
      'uid' => \Drupal::currentUser()->id(),
      'filename' => $file_name,
      'uri' => $file_path,
      'filemime' => mime_content_type($file_path),
      'filesize' => filesize($file_path),
    ]);
    $file->save();
    $this->fileUsage->add($file, 'tus', 'file', $file->id());
  }

}
