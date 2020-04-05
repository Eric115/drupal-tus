<?php

namespace Drupal\tus\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\tus\TusServer;
use Drupal\tus\TusServerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Finder\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Serializer;
use TusPhp\Tus\Server;

/**
 * Class TusServerController.
 */
class TusServerController extends ControllerBase {

  /**
   * Drupal\tus\TusServerInterface definition.
   *
   * @var \Drupal\tus\TusServerInterface
   */
  protected $tusServer;

  /**
   * The serializer.
   *
   * @var \Symfony\Component\Serializer\Serializer
   */
  protected $serializer;

  /**
   * The available serialization formats.
   *
   * @var array
   */
  protected $serializerFormats = [];

  /**
   * Instance of EntityFieldManager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a new TusServerController object.
   */
  public function __construct(TusServerInterface $tus_server, Serializer $serializer, array $serializer_formats, EntityFieldManagerInterface $entity_field_manager) {
    $this->tusServer = $tus_server;
    $this->serializer = $serializer;
    $this->serializerFormats = $serializer_formats;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    if ($container->hasParameter('serializer.formats') && $container->has('serializer')) {
      $serializer = $container->get('serializer');
      $formats = $container->getParameter('serializer.formats');
    }
    else {
      $formats = ['json'];
      $encoders = [new JsonEncoder()];
      $serializer = new Serializer([], $encoders);
    }

    return new static(
      $container->get('tus.server'),
      $serializer,
      $formats,
      $container->get('entity_field.manager')
    );
  }

  /**
   * Gets the format of the current request.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return string
   *   The format of the request.
   */
  protected function getRequestFormat(Request $request): ?string {
    $format = $request->getRequestFormat();
    if (!in_array($format, $this->serializerFormats)) {
      throw new BadRequestHttpException(sprintf('Unrecognized format: %s.', $format));
    }
    return $format;
  }

  /**
   * Upload a file via TUS protocol.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $uuid
   *   UUID for the file being uploaded.
   *
   * @return \TusPhp\Tus\Server
   *   Tus server ready to receive file upload.
   */
  public function upload(Request $request, $uuid) {
    $meta_values = $this->getMetaValuesFromRequest($request);

    // If no upload token (uuid) is provided, verify this request is genuine.
    // POST requests from Tus will not have a uuid, so this is normal.
    if (!$uuid) {
      $this->verifyRequest($request, $meta_values);
    }

    // UUID is passed on PATCH and other certain calls, or as the
    // header upload-key on others.
    $uuid = $uuid ?? $request->headers->get('upload-key') ?? '';
    $server = $this->tusServer->getServer($uuid, $meta_values);
    return $server->serve();
  }

  /**
   * Attempt to verify this is a genuine request from a user.
   *
   * Will throw an error if there are issues.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The original request.
   * @param array $meta_values
   *   Meta values extracted using ::getMetaValuesFromRequest().
   */
  protected function verifyRequest(Request $request, array $meta_values): void {
    // If any of these are missing we can't verify the upload or save it to a
    // field so there is no point in continuing.
    if (empty($meta_values['entityType']) ||
      empty($meta_values['entityBundle']) ||
      empty($meta_values['fieldName']) ||
      empty($meta_values['filetype'])) {
      throw new UnprocessableEntityHttpException('Required metadata fields not passed in.');
    }

    $bundle_fields = $this->entityFieldManager
      ->getFieldDefinitions($meta_values['entityType'], $meta_values['entityBundle']);
    $field_definition = $bundle_fields[$meta_values['fieldName']];

    // Check the uploaded file type is permitted by field.
    $allowed_extensions = explode(' ', $field_definition->getSettings()['file_extensions']);
    $file_type = end(explode('/', $meta_values['filetype']));

    if (!in_array($file_type, $allowed_extensions, TRUE)) {
      throw new UnprocessableEntityHttpException(sprintf('File type "%s" is not supported for this field.', $file_type));
    }
  }

  /**
   * Get an array of meta values from
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The original request.
   *
   * @return array
   *   An array of metadata values passed in with the request.
   */
  protected function getMetaValuesFromRequest(Request $request): array {
    $result = [];

    if ($metadata = $request->headers->get('upload-metadata')) {
      foreach (explode(',', $metadata) as $piece) {
        [$meta_name, $meta_value] = explode(' ', $piece);
        $result[$meta_name] = base64_decode($meta_value);
      }
    }

    return $result;
  }

  /**
   * Get the file ID of the uploaded file.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The created file details.
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   */
  public function uploadComplete(Request $request): Response {
    $response = [];
    $post_data = $this->serializer->decode($request->getContent(), $this->getRequestFormat($request));

    $file_query = $this->entityTypeManager()->getStorage('file')->getQuery();
    $file_query->condition('uri', $request->get('uuid') . '/' . $post_data['fileName'], 'CONTAINS');
    $results = $file_query->execute();

    if (!empty($results)) {
      $response['fid'] = reset($results);
    }

    return CacheableJsonResponse::create($response)
      ->setMaxAge(10);
  }

}
