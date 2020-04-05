<?php

namespace Drupal\tus;

/**
 * Interface TusServerInterface.
 */
interface TusServerInterface {

  /**
   * Configure and return TusServer instance.
   *
   * @param string $uploadKey
   *   The TUS upload key.
   * @param array $postData
   *   Array of file details from TUS client.
   *
   * @return TusServer
   *   The TusServer
   */
  public function getServer(string $uploadKey = '', array $postData = []): TusServerInterface;

  /**
   * Create the file in Drupal and send response.
   *
   * @param array $postData
   *   Array of file details from TUS client.
   *
   * @return array
   *   The created file details.
   */
  public function uploadComplete(array $postData = []): array;

}
