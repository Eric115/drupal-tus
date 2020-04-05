<?php

namespace Drupal\tus;

/**
 * Interface TusServerInterface.
 */
interface TusServerInterface {

  /**
   * Determine Drupal URI.
   *
   * Determine the Drupal URI for a file based on TUS upload key and meta params
   * from the upload client.
   *
   * @param string $uploadKey
   *   The TUS upload key.
   * @param array $fieldInfo
   *   Params about the entity type, bundle, and field_name.
   *
   * @return string
   *   The intended destination uri for the file.
   */
  public function determineDestination(string $uploadKey, array $fieldInfo = []);

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
  public function getServer(string $uploadKey = '', array $postData = []);

  /**
   * Create the file in Drupal and send response.
   *
   * @param array $postData
   *   Array of file details from TUS client.
   *
   * @return array
   *   The created file details.
   */
  public function uploadComplete(array $postData = []);

}
