<?php

namespace Drupal\filelink_usage\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure File Link Usage settings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['filelink_usage.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'filelink_usage_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('filelink_usage.settings');

    $form['verbose_logging'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Verbose logging'),
      '#default_value' => $config->get('verbose_logging'),
      '#description' => $this->t('Write detailed entries to the File Link Usage log channel.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('filelink_usage.settings')
      ->set('verbose_logging', $form_state->getValue('verbose_logging'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
