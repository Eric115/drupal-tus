<?php

namespace Drupal\tus\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Tus settings form.
 */
class TusSettingsForm extends ConfigFormBase {

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
    $form['cache_dir'] = [
      '#type' => 'textfield',
      '#description' => $this->t('Where to store the tus cache on the file system. <br> If unsure, use the default "private://tus"'),
      '#title' => $this->t('Tus cache directory'),
      '#default_value' => $this->config('tus.settings')->get('cache_dir') ?? 'private://tus',
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
      ->set('cache_dir', $form_state->getValue('cache_dir'))
      ->save();
  }

}
