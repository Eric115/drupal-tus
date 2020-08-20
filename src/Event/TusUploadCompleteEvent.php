<?php

namespace Drupal\tus\Event;

use Drupal\file\FileInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Event that is fired when an upload completes.
 */
class TusUploadCompleteEvent extends Event {

  /**
   * Name of dispatched event.
   */
  const EVENT_NAME = 'tus_upload_complete';

  /**
   * File object.
   *
   * @var \Drupal\file\FileInterface
   */
  protected $file;

  /**
   * Creates an instance of TusUploadCompleteEvent.
   */
  public function __construct(FileInterface $file) {
    $this->file = $file;
  }

}
