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

/**
 * Keeps the file_usage table in sync with hard‑coded links.
 */
class FileLinkUsageManager {

  /* --------------------------------------------------------------------- */
  /* Services & state                                                      */
  /* --------------------------------------------------------------------- */
  protected Connection                  $database;
  protected ConfigFactoryInterface      $configFactory;
  protected TimeInterface               $time;
  protected FileLinkUsageScanner        $scanner;
  protected FileUsageInterface          $fileUsage;
  protected EntityTypeManagerInterface  $entityTypeManager;
  protected FileLinkUsageNormalizer     $normalizer;

  /* Schema awareness. */
  protected bool $matchesHasEntityColumns;
  protected bool $statusHasEntityColumns;

  /* Per‑request cache‑tag helper. */
  private array $invalidatedFids = [];

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

    $schema                       = $database->schema();
    $this->matchesHasEntityColumns = $schema->fieldExists('filelink_usage_matches', 'entity_type')
      && $schema->fieldExists('filelink_usage_matches', 'entity_id');
    $this->statusHasEntityColumns  = $schema->fieldExists('filelink_usage_scan_status', 'entity_type')
      && $schema->fieldExists('filelink_usage_scan_status', 'entity_id');
  }

  /* --------------------------------------------------------------------- */
  /* Small helper – invalidate just once per request.                      */
  /* --------------------------------------------------------------------- */
  private function invalidateFile(int $fid): void {
    if (!isset($this->invalidatedFids[$fid])) {
      Cache::invalidateTags(['file:' . $fid, 'file_usage:' . $fid]);
      $this->entityTypeManager->getStorage('file')->resetCache([$fid]);
      $this->invalidatedFids[$fid] = TRUE;
    }
  }

  /* --------------------------------------------------------------------- */
  /* Public ‑ cron orchestration (restored)                                */
  /* --------------------------------------------------------------------- */
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

    /* Exit early if cron is not due (and not forced). */
    if (!$force_rescan && $interval && $last_scan + $interval > $now) {
      return;
    }

    /* Decide which nodes to scan. */
    if (!$force_rescan) {
      $threshold = $interval ? $now - $interval : $now;
      $query     = $this->database->select('node_field_data', 'n');

      if ($this->statusHasEntityColumns) {
        $query->leftJoin(
          'filelink_usage_scan_status',
          's',
          'n.nid = s.entity_id AND s.entity_type = :t',
          [':t' => 'node']
        );
      }
      else {
        $query->leftJoin('filelink_usage_scan_status', 's', 'n.nid = s.nid');
      }

      $query->fields('n', ['nid']);
      $or = $query->orConditionGroup()
        ->isNull('s.scanned')
        ->condition('s.scanned', $threshold, '<');
      $nids = $query->condition($or)->execute()->fetchCol();
    }
    else {
      $nids = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid'])
        ->execute()
        ->fetchCol();
    }

    /* Scan & reconcile. */
    if ($nids) {
      $this->scanner->scan($nids);
      foreach ($nids as $nid) {
        $this->reconcileNodeUsage((int) $nid);
      }
    }

    $config->set('last_scan', $now)->save();
  }

  /* --------------------------------------------------------------------- */
  /* Public – mark for rescan helpers (unchanged)                          */
  /* --------------------------------------------------------------------- */
  public function markForRescan(ContentEntityInterface $entity): void {
    $key = $this->statusHasEntityColumns ? 'entity_id' : 'nid';

    $this->database->delete('filelink_usage_scan_status')
      ->condition($key, $entity->id())
      ->execute();
  }

  public function markAllForRescan(): void {
    $this->database->truncate('filelink_usage_scan_status')->execute();
  }

  /* --------------------------------------------------------------------- */
  /* Public – reconcile usage for one entity                               */
  /* --------------------------------------------------------------------- */
  public function reconcileEntityUsage(string $entity_type_id, int $id, bool $deleted = FALSE): void {
    /* ----- 1. links recorded for entity -------------------------------- */
    $query = $this->database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link']);

    if ($this->matchesHasEntityColumns) {
      $query->condition('entity_type', $entity_type_id)
            ->condition('entity_id', $id);
    }
    else {
      if ($entity_type_id !== 'node') {
        return; // legacy schema only covers nodes
      }
      $query->condition('nid', $id);
    }

    $links        = $query->execute()->fetchCol();
    $file_storage = $this->entityTypeManager->getStorage('file');
    $files        = [];
    foreach ($links as $link) {
      $uri   = $this->normalizer->normalize($link);
      $found = $file_storage->loadByProperties(['uri' => $uri]);
      $file  = $found ? reset($found) : NULL;
      if ($file) {
        $files[$file->id()] = $file;
      }
    }

    /* ----- 2. current usage rows -------------------------------------- */
    $usage_rows = $this->database->select('file_usage', 'fu')
      ->fields('fu', ['fid', 'count'])
      ->condition('type', $entity_type_id)
      ->condition('id', $id)
      ->condition('module', 'filelink_usage')
      ->execute()
      ->fetchAllKeyed();

    /* ----- 3. entity deleted – purge everything ----------------------- */
    if ($deleted) {
      foreach ($usage_rows as $fid => $count) {
        if ($file = $file_storage->load($fid)) {
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $entity_type_id, $id);
          }
          $this->invalidateFile($fid);
        }
      }

      /* remove matches & status rows */
      $del_matches = $this->database->delete('filelink_usage_matches');
      $del_status  = $this->database->delete('filelink_usage_scan_status');

      if ($this->matchesHasEntityColumns) {
        $del_matches->condition('entity_type', $entity_type_id)
                    ->condition('entity_id', $id);
        $del_status->condition('entity_type', $entity_type_id)
                   ->condition('entity_id', $id);
      }
      else {
        $del_matches->condition('nid', $id);
        $del_status->condition('nid', $id);
      }
      $del_matches->execute();
      $del_status->execute();
      return;
    }

    /* ----- 4. remove stale usage -------------------------------------- */
    foreach ($usage_rows as $fid => $count) {
      if (!isset($files[$fid]) && ($file = $file_storage->load($fid))) {
        while ($count-- > 0) {
          $this->fileUsage->delete($file, 'filelink_usage', $entity_type_id, $id);
        }
        $this->invalidateFile($fid);
      }
    }

    /* ----- 5. add missing usage --------------------------------------- */
    foreach ($files as $fid => $file) {
      if (!isset($usage_rows[$fid])) {
        $this->fileUsage->add($file, 'filelink_usage', $entity_type_id, $id);
        $this->invalidateFile($fid);
      }
    }
  }

  /* Convenience wrappers */
  public function reconcileNodeUsage(int $nid, bool $deleted = FALSE): void {
    $this->reconcileEntityUsage('node', $nid, $deleted);
  }

  public function cleanupNode(NodeInterface $node): void {
    $this->reconcileEntityUsage('node', $node->id(), TRUE);
  }

  /* --------------------------------------------------------------------- */
  /* Hook helper – new file created                                        */
  /* --------------------------------------------------------------------- */
  public function addUsageForFile(FileInterface $file): void {
    if (strpos($file->getFileUri(), 'public://') !== 0) {
      return;
    }

    /* which entities link to this file? */
    $query = $this->database->select('filelink_usage_matches', 'f')
      ->condition('link', $file->getFileUri());

    if ($this->matchesHasEntityColumns) {
      $query->fields('f', ['entity_type', 'entity_id']);
      $records = $query->execute()->fetchAll();
    }
    else {
      $query->fields('f', ['nid']);
      $nids    = $query->execute()->fetchCol();
      $records = [];
      foreach ($nids as $nid) {
        $records[] = (object) ['entity_type' => 'node', 'entity_id' => $nid];
      }
    }

    if (!$records) {
      return;
    }

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
        $this->invalidateFile($file->id());
      }
    }
  }

}
