<?php

namespace Drupal\tus\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StreamWrapper\StreamWrapperManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tus settings form.
 */
class TusSettingsForm extends ConfigFormBase {

  /**
   * Instance of StreamWrapperManager.
   *
   * @var \Drupal\Core\StreamWrapper\StreamWrapperManagerInterface
   */
  protected $streamWrapperManager;

  /**
   * Creates an instance of TusSettingsForm.
   */
  public function __construct(ConfigFactoryInterface $config_factory, StreamWrapperManagerInterface $stream_wrapper_manager) {
    parent::__construct($config_factory);
    $this->streamWrapperManager = $stream_wrapper_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('stream_wrapper_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'tus.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'tus_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $scheme_options = ['public://' => 'public'];

    if ($this->streamWrapperManager->isValidScheme('private')) {
      $scheme_options['private://'] = 'private';
    }
    $form['scheme'] = [
      '#type' => 'radios',
      '#description' => $this->t('File system to store cache'),
      '#title' => $this->t('File system'),
      '#options' => $scheme_options,
      '#default_value' => $this->config('tus.settings')->get('scheme'),
      '#required' => TRUE,
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $this->config('tus.settings')
      ->set('scheme', $form_state->getValue('scheme'))
      ->save();
  }

}
