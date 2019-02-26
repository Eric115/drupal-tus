<?php

namespace Drupal\tus;

use TusPhp\Tus\Server as TusPhp;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Class TusServer.
 */
class TusServer implements TusServerInterface {

  /**
   * The private_tempstore factory.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStoreFactory
   */
  protected $tempStore;

  /**
   * Constructs a new TusServer object.
   */
  public function __construct(PrivateTempStoreFactory $temp_store_factory) {
    $this->tempStore = $temp_store_factory->get('tus');
  }

  /**
  * {@inheritdoc}
  */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('tempstore.private')
    );
  }

  /**
   * Configure and return TusServer instance.
   *
   * @param string $uploadKey
   *   UUID for the file being uploaded.
   *
   * @return TusServer
   */
  public function getServer($uploadKey = '', $postData = []) {
    // Set TUS config cache directory.
    \TusPhp\Config::set([
      'file' => [
        'dir' => drupal_realpath('private://tus') . '/',
        'name' => 'tus_php.cache',
      ]
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

    // If no post data, we can't proceed.
    if (empty($postData['entityType'])) {
      \Drupal::logger('iam')->error('TusServer: getServer() Error, no POST meta returned');
      return;
    }

    // Determine TUS uploadDir.
    $bundleFields = \Drupal::getContainer()->get('entity_field.manager')
      ->getFieldDefinitions($postData['entityType'], $postData['entityBundle']);
    $fieldDefinition = $bundleFields[$postData['fieldName']];
    // Get the field's configured destination directory.
    $filePath = trim(\Drupal::service('token')->replace($fieldDefinition->getSetting('file_directory')), '/');
    $destination = $fieldDefinition->getSetting('uri_scheme') . '://';
    $destination .= $filePath;

    // Get uploadKey for directory creation. On POST, it isn't passed, but
    // we need to add the UUID to file directory to ensure we don't
    // concatenate same-file uploads if client key is lost.
    if ($requestMethod == 'post') {
      $uploadKey = $server->getUploadKey();
    }
    $destination .= '/' . $uploadKey;

    // Ensure directory creation.
    if (!file_prepare_directory($destination, FILE_CREATE_DIRECTORY)) {
      throw new HttpException(500, 'Destination file path:' . $destination . ' is not writable');
    }
    // Store Drupal's file URI for saving later.
    $fileName = $postData['name'] ?? $postData['filename'];
    // Add original encoded base64 name for privateTempStore index.
    $fileName = substr(base64_encode($fileName), 0, 70);
    $this->tempStore->set("dest-{$fileName}", $destination);
    // Set the upload directory for TUS.
    $server->setUploadDir(drupal_realpath($destination));

    return $server;
  }

  /**
   * Create the file in Drupal and send response.
   *
   * @param array  $postData
   *   Array of file details from TUS client.
   *
   * @return array
   *   The created file details.
   */
  public function uploadComplete($postData = []) {
    // If no post data, we can't proceed.
    if (empty($postData['file'])) {
      \Drupal::logger('iam')->error('TusServer: uploadComplete() Error, no POST file returned');
      return [];
    }

    // Get our destination from tempstore.
    $fileName = substr(base64_encode($postData['file']['name']), 0, 70);
    $destination = $this->tempStore->get("dest-{$fileName}");

    // Create the file entity.
    $file = File::create([
      'uid' => \Drupal::currentUser()->id(),
      'filename' => $postData['file']['name'],
      'uri' => $destination . '/' .  $postData['file']['name'],
      'filemime' => $postData['file']['type'],
      'filesize' => $postData['file']['size'],
    ]);
    $file->save();

    // Create file_managed entry so the file isn't deleted before
    // containing entity is saved.
    // @todo Make this an opt-in setting.
    $fileUsage = \Drupal::service('file.usage');
    $fileUsage->add($file, 'tus', 'file', $file->id());

    $result = [
      'fid' => $file->id(),
      'uuid' => $file->uuid(),
      'mimetype' => $file->getMimeType(),
      'filename' => $file->getFilename(),
      'path' => $file->url(),
    ];

    return $result;
  }

}
