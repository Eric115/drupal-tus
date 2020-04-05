<?php

namespace Drupal\tus;

use TusPhp\Events\TusEvent;
use TusPhp\Tus\Server;

/**
 * Interface TusServerInterface.
 */
interface TusServerInterface {

  /**
   * Configure and return TusServer instance.
   *
   * @param string $upload_key
   *   The TUS upload key.
   * @param array $post_data
   *   Array of file details from TUS client.
   *
   * @return \TusPhp\Tus\Server
   *   The TusServer
   */
  public function getServer(string $upload_key = '', array $post_data = []): Server;

  /**
   * Create the managed file in Drupal.
   *
   * @param \TusPhp\Events\TusEvent $event
   *   Tus event object.
   */
  public function uploadComplete(TusEvent $event): void;

}
