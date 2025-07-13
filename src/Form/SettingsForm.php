<?php
declare(strict_types=1);

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
  protected function getEditableConfigNames(): array {
    return ['filelink_usage.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'filelink_usage_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('filelink_usage.settings');

    $count = \Drupal::database()
      ->select('filelink_usage_matches')
      ->countQuery()
      ->execute()
      ->fetchField();

    $form['match_count'] = [
      '#type' => 'markup',
      '#markup' => $this->t('Detected hard-coded links: @count', ['@count' => $count]),
      '#weight' => -100,
      '#cache' => [
        'max-age' => 0,
      ],
    ];

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
        'every' => $this->t('Every cron run'),
        'hourly' => $this->t('Hourly'),
        'daily' => $this->t('Daily'),
        'weekly' => $this->t('Weekly'),
        'monthly' => $this->t('Monthly'),
        'yearly' => $this->t('Yearly'),
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
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->configFactory->getEditable('filelink_usage.settings')
      ->set('verbose_logging', $form_state->getValue('verbose_logging'))
      ->set('scan_frequency', $form_state->getValue('scan_frequency'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Purges saved file link matches from the database.
   */
  public function purgeFileLinkMatches(array $form, FormStateInterface $form_state): void {
    $connection = Drupal::database();
    $connection->truncate('filelink_usage_matches')->execute();
    \Drupal::service('filelink_usage.manager')->markAllForRescan();

    // Mark all nodes for rescan in case additional modules rely on this hook.
    $storage = Drupal::entityTypeManager()->getStorage('node');
    $nids = $storage->getQuery()->accessCheck(FALSE)->execute();
    if ($nids) {
      $nodes = $storage->loadMultiple($nids);
      foreach ($nodes as $node) {
        filelink_usage_mark_for_rescan($node);
      }
    }

    $this->configFactory->getEditable('filelink_usage.settings')
      ->set('last_scan', 0)
      ->save();

    $this->messenger()->addMessage($this->t('All saved file links have been purged.'));
    $form_state->setRebuild();
  }

}
