<?php

namespace Drupal\tus;

/**
 * Interface TusServerInterface.
 */
interface TusServerInterface {

  /**
   * Configure and return TusServer instance.
   *
   * @return TusServer
   */
  public function getServer($uploadKey = '', $postData = []);

  /**
   * Create the file in Drupal and send response.
   *
   * @param array  $postData
   *   Array of file details from TUS client.
   *
   * @return array
   *   The created file details.
   */
  public function uploadComplete($postData = []);
}
