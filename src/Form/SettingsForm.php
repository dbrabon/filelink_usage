<?php

namespace Drupal\filelink_usage\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal;

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

    $form['scan_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Cron scan frequency'),
      '#options' => [
        'hourly' => $this->t('Hourly'),
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
      ],
      '#default_value' => $config->get('scan_frequency'),
      '#description' => $this->t('How often cron should scan for file links.'),
    ];

    $form['actions']['purge'] = [
      '#type' => 'submit',
      '#value' => $this->t('Purge saved file links'),
      '#submit' => ['::purgeFileLinkMatches'],
      '#limit_validation_errors' => [],
      '#button_type' => 'danger',
      '#weight' => 10,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('filelink_usage.settings')
      ->set('verbose_logging', $form_state->getValue('verbose_logging'))
      ->set('scan_frequency', $form_state->getValue('scan_frequency'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Purges saved file link matches from the database.
   */
  public function purgeFileLinkMatches(array &$form, FormStateInterface $form_state) {
    Drupal::database()->truncate('filelink_usage_matches')->execute();
    $this->messenger()->addMessage($this->t('All saved file links have been purged.'));
    $form_state->setRebuild();
  }

}
