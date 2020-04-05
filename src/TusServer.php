<?php

namespace Drupal\tus;

use TusPhp\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\FileUsage\FileUsageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use TusPhp\Tus\Server as TusPhp;
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
   * Constructs a new TusServer object.
   */
  public function __construct(EntityFieldManagerInterface $entity_field_manager, Token $token, FileUsageInterface $file_usage, EntityTypeManagerInterface $entity_type_manager, FileSystemInterface $file_system, ConfigFactoryInterface $config_factory) {
    $this->entityFieldManager = $entity_field_manager;
    $this->token = $token;
    $this->fileUsage = $file_usage;
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->config = $config_factory->get('tus.settings');
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
  public function determineDestination($uploadKey, $fieldInfo = []) {
    $destination = '';
    // If fieldInfo was not passed, we cannot determine file path.
    if (empty($fieldInfo)) {
      throw new HttpException(500, 'Destination file path unknown because field info not sent in client meta.');
    }

    // Determine TUS uploadDir.
    $bundleFields = $this->entityFieldManager
      ->getFieldDefinitions($fieldInfo['entityType'], $fieldInfo['entityBundle']);
    $fieldDefinition = $bundleFields[$fieldInfo['fieldName']];
    // Get the field's configured destination directory.
    $filePath = trim($this->token->replace($fieldDefinition->getSetting('file_directory')), '/');
    $destination = $fieldDefinition->getSetting('uri_scheme') . '://';
    $destination .= $filePath;

    // Add the UploadKey to destination.
    $destination .= '/' . $uploadKey;

    // Ensure directory creation.
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, 'Destination file path:' . $destination . ' is not writable.');
    }

    return $destination;
  }

  /**
   * {@inheritdoc}
   */
  public function getServer(string $uploadKey = '', array $postData = []) {
    $tusCacheDir = ($this->config->get('scheme') ?? 'public://') . 'tus';

    // Ensure TUS cache directory exists.
    if (!$this->fileSystem->prepareDirectory($tusCacheDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new HttpException(500, sprintf('TUS cache folder "%s" is not writable.', $tusCacheDir));
    }
    // Set TUS config cache directory.
    Config::set([
      'file' => [
        'dir' => $this->fileSystem->realpath($tusCacheDir) . '/',
        'name' => 'tus_php.cache',
      ],
    ]);

    // Initialize TUS server.
    $server = new TusPhp();
    $server->setApiPath('/tus/upload');

    // Set uploadKey if passed.
    if (!empty($uploadKey)) {
      $server->setUploadKey($uploadKey);
    }

    // These methods won't pass metadata about the file, and don't need
    // file directory, because they are reading from TUS cache, so we
    // can return the server now.
    $requestMethod = strtolower($server->getRequest()->method());
    $fastReturnMethods = [
      'get',
      'head',
      'patch',
    ];
    if (in_array($requestMethod, $fastReturnMethods)) {
      return $server;
    }

    // Get uploadKey for directory creation. On POST, it isn't passed, but
    // we need to add the UUID to file directory to ensure we don't
    // concatenate same-file uploads if client key is lost.
    if ($requestMethod == 'post') {
      $uploadKey = $server->getUploadKey();
    }

    // Get the file destination.
    $destination = $this->determineDestination($uploadKey, $postData);
    // Set the upload directory for TUS.
    $server->setUploadDir($destination);

    return $server;
  }

  /**
   * {@inheritdoc}
   */
  public function uploadComplete(array $postData = []) {
    // If no post data, we can't proceed.
    if (empty($postData['file'])) {
      throw new HttpException(500, 'TUS uploadComplete did not receive file info.');
    }
    $fileExists = $fileEntityExists = $addUsage = FALSE;
    $fileUsage = $this->fileUsage;

    // Get UploadKey from Uppy response.
    $uploadUrlArray = explode('/', $postData['response']['uploadURL']);
    $uploadKey = array_pop($uploadUrlArray);

    // Get file destination.
    $destination = $this->determineDestination($uploadKey, $postData['file']['meta']);
    $fileName = $postData['file']['name'];
    $fileUri = $destination . '/' . $fileName;

    // Check if we have a file_managed record for the file anywhere.
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $fileQuery = $fileStorage->getQuery();

    // Check if the file already exists.  Re-use the existing entity if so.
    // We can do this because even if the filenames are the same on 2 different
    // files, the checksum performed by TUS will cause a new uploadKey, and
    // therefor a new folder and file entity entry.
    if (file_exists($fileUri)) {
      $fileQuery->condition('uri', $fileUri);
    }
    else {
      // We can look for this TUS-uuid + filename for the existence of this
      // file, possibly uploaded for a different field.
      $fileQuery->condition('uri', "%{$uploadKey}/{$fileName}", 'LIKE');
      $addUsage = TRUE;
    }

    $fileCheck = $fileQuery->execute();
    // If we found the file in database.
    if (!empty($fileCheck)) {
      $file = $fileStorage->load(reset($fileCheck));
      // Mark that the file exists, so we can re-use the entity.
      $fileEntityExists = TRUE;
      // Check if this file URI truly exists in path.
      if (file_exists($file->getFileUri())) {
        $fileExists = TRUE;
      }
    }

    // If the file didn't already exist, create the record now.
    if ($fileExists && !$fileEntityExists) {
      // Create the file entity.
      $file = File::create([
        'uid'      => \Drupal::currentUser()->id(),
        'filename' => $fileName,
        'uri'      => $fileUri,
        'filemime' => $postData['file']['type'],
        'filesize' => $postData['file']['size'],
      ]);
      $file->save();

      // Create file_usage entry so the file isn't deleted before
      // containing entity is saved.
      // Entries are deleted in tus_cron if another file_usage is detected.
      // e.g. once the file is assigned to an entity.
      $fileUsage->add($file, 'tus', 'file', $file->id());
    }
    elseif (empty($file)) {
      // Return error.
      throw new HttpException(406, 'There was an issue uploading this file.');
    }

    if ($addUsage) {
      // We need to record new usage, but we don't know the entity ID it is
      // assigned to, so mark the entity type as module, because 'tus' will be
      // removed in tus_cron. Leave 'file' as the object so that the link in
      // file admin list is valid.
      $fileUsage->add($file, $postData['file']['meta']['entityType'], 'file', $file->id());
    }

    // Return a useful result payload for front end clients.
    $result = [
      'fid' => $file->id(),
      'uuid' => $file->uuid(),
      'mimetype' => $file->getMimeType(),
      'filename' => $file->getFilename(),
      'path' => $file->toUrl()->toString(),
    ];

    // Allow modules to alter the response payload.
    \Drupal::moduleHandler()->alter('tus_upload_complete', $result, $file);

    return $result;
  }

}
