<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\node\NodeInterface;
use Drupal\file\FileInterface;
use Drupal\filelink_usage\FileLinkUsageNormalizer;

/**
 * Keeps the `file_usage` table in sync with links recorded in
 * `filelink_usage_matches`.
 *
 * The class is **schema‑aware**:
 *   • Legacy schema — columns: `nid`, `link`, `timestamp`  
 *   • Generalised schema — columns: `entity_type`, `entity_id`, `link`, `timestamp`
 *
 * No SQL errors are thrown regardless of which schema is present.
 */
class FileLinkUsageManager {

  /* -----------------------------------------------------------------------
   * Dependencies
   * --------------------------------------------------------------------- */
  protected Connection                  $database;
  protected ConfigFactoryInterface      $configFactory;
  protected TimeInterface               $time;
  protected FileLinkUsageScanner        $scanner;
  protected FileUsageInterface          $fileUsage;
  protected EntityTypeManagerInterface  $entityTypeManager;
  protected FileLinkUsageNormalizer     $normalizer;

  /* -----------------------------------------------------------------------
   * Runtime capabilities
   * --------------------------------------------------------------------- */
  protected bool $matchesHasEntityColumns;
  protected bool $statusHasEntityColumns;

  public function __construct(
    Connection                 $database,
    ConfigFactoryInterface     $configFactory,
    TimeInterface              $time,
    FileLinkUsageScanner       $scanner,
    FileUsageInterface         $fileUsage,
    EntityTypeManagerInterface $entityTypeManager,
    FileLinkUsageNormalizer    $normalizer,
  ) {
    $this->database          = $database;
    $this->configFactory     = $configFactory;
    $this->time              = $time;
    $this->scanner           = $scanner;
    $this->fileUsage         = $fileUsage;
    $this->entityTypeManager = $entityTypeManager;
    $this->normalizer        = $normalizer;

    $schema = $database->schema();
    $this->matchesHasEntityColumns = $schema->fieldExists('filelink_usage_matches', 'entity_type')
      && $schema->fieldExists('filelink_usage_matches', 'entity_id');
    $this->statusHasEntityColumns  = $schema->fieldExists('filelink_usage_scan_status', 'entity_type')
      && $schema->fieldExists('filelink_usage_scan_status', 'entity_id');
  }

  /* =========================================================================
   * Cron orchestration
   * ========================================================================= */

  /**
   * Execute cron scanning based on configured frequency.
   */
  public function runCron(): void {
    $config      = $this->configFactory->getEditable('filelink_usage.settings');
    $frequency   = $config->get('scan_frequency');
    $last_scan   = (int) $config->get('last_scan');
    $intervals   = [
      'every'   => 0,
      'hourly'  => 3600,
      'daily'   => 86400,
      'weekly'  => 604800,
      'monthly' => 2592000,
      'yearly'  => 31536000,
    ];
    $interval    = $intervals[$frequency] ?? 31536000;
    $now         = $this->time->getRequestTime();

    $match_count = (int) $this->database->select('filelink_usage_matches')
      ->countQuery()
      ->execute()
      ->fetchField();
    $force_rescan = $match_count === 0;

    if (!$force_rescan && $interval && $last_scan + $interval > $now) {
      return;
    }

    // Determine which nodes need rescanning.
    if (!$force_rescan) {
      $threshold = $interval ? $now - $interval : $now;
      $query = $this->database->select('node_field_data', 'n');

      if ($this->statusHasEntityColumns) {
        $query->leftJoin(
          'filelink_usage_scan_status',
          's',
          'n.nid = s.entity_id AND s.entity_type = :t',
          [':t' => 'node']
        );
        $query->fields('n', ['nid']);
        $or = $query->orConditionGroup()
          ->isNull('s.scanned')
          ->condition('s.scanned', $threshold, '<');
        $nids = $query->condition($or)->execute()->fetchCol();
      }
      else {
        // Legacy schema (column "nid").
        $query->leftJoin('filelink_usage_scan_status', 's', 'n.nid = s.nid');
        $query->fields('n', ['nid']);
        $or = $query->orConditionGroup()
          ->isNull('s.scanned')
          ->condition('s.scanned', $threshold, '<');
        $nids = $query->condition($or)->execute()->fetchCol();
      }
    }
    else {
      $nids = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid'])
        ->execute()
        ->fetchCol();
    }

    if ($nids) {
      $this->scanner->scan($nids);      // Updates matches + scan status.
      foreach ($nids as $nid) {
        $this->reconcileNodeUsage((int) $nid);
      }
    }

    $config->set('last_scan', $now)->save();
  }

  /* =========================================================================
   * Scan‑status helpers
   * ========================================================================= */

  public function markForRescan(ContentEntityInterface $entity): void {
    $delete = $this->database->delete('filelink_usage_scan_status');
    if ($this->statusHasEntityColumns) {
      $delete->condition('entity_type', $entity->getEntityTypeId())
             ->condition('entity_id', $entity->id());
    }
    else {
      $delete->condition('nid', $entity->id());
    }
    $delete->execute();
  }

  public function markAllForRescan(): void {
    $this->database->truncate('filelink_usage_scan_status')->execute();
  }

  /* =========================================================================
   * Usage reconciliation
   * ========================================================================= */

  /**
   * Reconcile file‑usage rows for a specific entity.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param int    $id
   *   The entity ID.
   * @param bool   $deleted
   *   If TRUE, the entity no longer exists; remove all usage.
   */
  public function reconcileEntityUsage(string $entity_type_id, int $id, bool $deleted = FALSE): void {
    /* ----------------------------------------------------------------------
     * 1. Fetch links recorded for this entity.
     * -------------------------------------------------------------------- */
    $query = $this->database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link']);

    if ($this->matchesHasEntityColumns) {
      $query->condition('entity_type', $entity_type_id)
            ->condition('entity_id', $id);
    }
    else {
      if ($entity_type_id !== 'node') {
        // Legacy schema cannot hold non‑node data.
        return;
      }
      $query->condition('nid', $id);
    }

    $links = $query->execute()->fetchCol();

    /* Load file entities for those links. */
    $file_storage = $this->entityTypeManager->getStorage('file');
    $files = [];
    foreach ($links as $link) {
      $uri   = $this->normalizer->normalize($link);
      $found = $file_storage->loadByProperties(['uri' => $uri]);
      $file  = $found ? reset($found) : NULL;
      if ($file) {
        $files[$file->id()] = $file;
      }
    }

    /* ----------------------------------------------------------------------
     * 2. Inspect existing usage rows for this entity.
     * -------------------------------------------------------------------- */
    $usage_rows = $this->database->select('file_usage', 'fu')
      ->fields('fu', ['fid', 'count'])
      ->condition('type', $entity_type_id)
      ->condition('id', $id)
      ->condition('module', 'filelink_usage')
      ->execute()
      ->fetchAllKeyed();

    /* ----------------------------------------------------------------------
     * 3. If the entity has been deleted, purge everything.
     * -------------------------------------------------------------------- */
    if ($deleted) {
      foreach ($usage_rows as $fid => $count) {
        $file = $file_storage->load($fid);
        if ($file) {
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $entity_type_id, $id);
          }
          Cache::invalidateTags(['file:' . $fid]);
        }
      }

      /* Clean up matches + status. */
      $delete_matches = $this->database->delete('filelink_usage_matches');
      $delete_status  = $this->database->delete('filelink_usage_scan_status');

      if ($this->matchesHasEntityColumns) {
        $delete_matches->condition('entity_type', $entity_type_id)
                       ->condition('entity_id', $id);
        $delete_status->condition('entity_type', $entity_type_id)
                      ->condition('entity_id', $id);
      }
      else {
        $delete_matches->condition('nid', $id);
        $delete_status->condition('nid', $id);
      }

      $delete_matches->execute();
      $delete_status->execute();
      return;
    }

    /* ----------------------------------------------------------------------
     * 4. Remove stale usage rows.
     * -------------------------------------------------------------------- */
    foreach ($usage_rows as $fid => $count) {
      if (!isset($files[$fid])) {
        $file = $file_storage->load($fid);
        if ($file) {
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $entity_type_id, $id);
          }
          Cache::invalidateTags(['file:' . $fid]);
        }
      }
    }

    /* ----------------------------------------------------------------------
     * 5. Add missing usage rows.
     * -------------------------------------------------------------------- */
    foreach ($files as $fid => $file) {
      if (!isset($usage_rows[$fid])) {
        $this->fileUsage->add($file, 'filelink_usage', $entity_type_id, $id);
        Cache::invalidateTags(['file:' . $fid]);
      }
    }
  }

  /* -----------------------------------------------------------------------
   * Convenience wrappers
   * --------------------------------------------------------------------- */

  public function reconcileNodeUsage(int $nid, bool $deleted = FALSE): void {
    $this->reconcileEntityUsage('node', $nid, $deleted);
  }

  public function cleanupNode(NodeInterface $node): void {
    $this->reconcileEntityUsage('node', $node->id(), TRUE);
  }

  /* =========================================================================
   * Hook helper: When a new file is created, add usage rows immediately.
   * ========================================================================= */

  public function addUsageForFile(FileInterface $file): void {
    $uri = $file->getFileUri();
    if (strpos($uri, 'public://') !== 0) {
      return;
    }

    /* Find entities referencing this file in matches table. */
    $query = $this->database->select('filelink_usage_matches', 'f')
      ->condition('link', $uri);

    if ($this->matchesHasEntityColumns) {
      $query->fields('f', ['entity_type', 'entity_id']);
      $records = $query->execute()->fetchAll();
    }
    else {
      // Legacy schema.
      $query->fields('f', ['nid']);
      $nids = $query->execute()->fetchCol();
      $records = [];
      foreach ($nids as $nid) {
        $records[] = (object) ['entity_type' => 'node', 'entity_id' => $nid];
      }
    }

    if (!$records) {
      return;
    }

    /* Build a lookup of current usage so we do not duplicate rows. */
    $usage = $this->fileUsage->listUsage($file);
    $used  = [];
    foreach ($usage as $module_usage) {
      foreach ($module_usage as $type => $ids) {
        $used[$type] = ($used[$type] ?? []) + $ids;
      }
    }

    foreach ($records as $record) {
      $type = $record->entity_type;
      $id   = $record->entity_id;

      if (empty($used[$type][$id])) {
        $this->fileUsage->add($file, 'filelink_usage', $type, $id);
        Cache::invalidateTags(['file:' . $file->id()]);
      }
    }
  }

}
