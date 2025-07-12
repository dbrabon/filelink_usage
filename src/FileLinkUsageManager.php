<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\file\FileInterface;
use Drupal\node\NodeInterface;

/**
 * Manages scanning, cache‑refresh, and cleanup for FileLink Usage.
 */
class FileLinkUsageManager {

  protected Connection $database;
  protected ConfigFactoryInterface $configFactory;
  protected TimeInterface $time;
  protected FileLinkUsageScanner $scanner;
  protected FileUsageInterface $fileUsage;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileLinkUsageNormalizer $normalizer;
  protected bool $statusHasEntityColumns;
  protected bool $matchesHasEntityColumns;

  public function __construct(
    Connection $database,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    FileLinkUsageScanner $scanner,
    FileUsageInterface $fileUsage,
    EntityTypeManagerInterface $entityTypeManager,
    FileLinkUsageNormalizer $normalizer
  ) {
    $this->database            = $database;
    $this->configFactory       = $configFactory;
    $this->time                = $time;
    $this->scanner             = $scanner;
    $this->fileUsage           = $fileUsage;
    $this->entityTypeManager   = $entityTypeManager;
    $this->normalizer          = $normalizer;

    // Detect new/legacy schemas on install/upgrade.
    $this->statusHasEntityColumns  = $this->database->schema()
      ->fieldExists('filelink_usage_scan_status', 'entity_type');
    $this->matchesHasEntityColumns = $this->database->schema()
      ->fieldExists('filelink_usage_matches', 'entity_type');
  }

  /* -------------------------------------------------------------------------
   *  Public API
   * ---------------------------------------------------------------------- */

  /**
   * Lightweight callback for hook_entity_insert() on *file* entities.
   *
   * Keeps the original hook happy (older versions expected this), but we
   * currently do not need to add any usage when a new file is created.
   * We simply invalidate the file’s own cache‑tag so the “Used In” column
   * appears immediately with “0”.
   */
  public function addUsageForFile(FileInterface $file): void {
    \Drupal::service('cache_tags.invalidator')->invalidateTags(['file:' . $file->id()]);
  }

  /**
   * Execute cron scan for entities needing re‑scan.
   */
  public function runCron(): void {
    $config     = $this->configFactory->getEditable('filelink_usage.settings');
    $frequency  = $config->get('scan_frequency');
    $last_scan  = (int) $config->get('last_scan');

    $intervals = [
      'every'   => 0,
      'hourly'  => 3600,
      'daily'   => 86400,
      'weekly'  => 604800,
      'monthly' => 2592000,
      'yearly'  => 31536000,
    ];
    $interval   = $intervals[$frequency] ?? 31536000;
    $now        = $this->time->getRequestTime();

    // Force a full scan the very first time (empty matches table).
    $needs_full = !(bool) $this->database->select('filelink_usage_matches')
      ->countQuery()->execute()->fetchField();

    // Skip if interval not reached and no forced full scan.
    if (!$needs_full && $interval && ($last_scan + $interval > $now)) {
      return;
    }

    // Decide which entities to scan.
    [$nids, $block_ids, $term_ids, $comment_ids] = $needs_full
      ? $this->collectAllEntityIds()
      : $this->collectStaleNodeIds($now - $interval);

    $entities = [];
    if ($nids)        { $entities['node']          = $nids; }
    if ($block_ids)   { $entities['block_content'] = $block_ids; }
    if ($term_ids)    { $entities['taxonomy_term'] = $term_ids; }
    if ($comment_ids) { $entities['comment']       = $comment_ids; }

    if ($entities) {
      $this->scanner->scan($entities);
    }

    $config->set('last_scan', $now)->save();
  }

  /**
   * Mark an entity for re‑scan (e.g. from hook_entity_update()).
   */
  public function markEntityForScan(string $entity_type_id, int $entity_id): void {
    if ($entity_type_id === 'node') {
      if ($this->statusHasEntityColumns) {
        $this->database->merge('filelink_usage_scan_status')
          ->keys(['entity_type' => 'node', 'entity_id' => $entity_id])
          ->fields(['scanned' => 0])
          ->execute();
      }
      else {
        $this->database->merge('filelink_usage_scan_status')
          ->keys(['nid' => $entity_id])
          ->fields(['scanned' => 0])
          ->execute();
      }
    }
  }

  /**
   * Mark all entities for re-scan by clearing scan status table.
   */
  public function markAllForRescan(): void {
    $this->database->truncate('filelink_usage_scan_status')->execute();
  }

  /**
   * Remove usage records when a node is deleted.
   */
  public function cleanupNode(NodeInterface $node): void {
    $this->reconcileEntityUsage('node', $node->id(), TRUE);
  }

  /**
   * Remove usage records for a deleted file.
   */
  public function removeFileUsage(FileInterface $file): void {
    $usage = $this->fileUsage->listUsage($file);
    if (!empty($usage['filelink_usage'])) {
      foreach ($usage['filelink_usage'] as $etype => $ids) {
        foreach ($ids as $id => $count) {
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $etype, $id);
          }
        }
      }
    }
    $this->database->delete('filelink_usage_matches')
      ->condition('link', $file->getFileUri())
      ->execute();
  }

  /**
   * Reconcile usage for an entity (called on update or delete).
   */
  public function reconcileEntityUsage(
    string $entity_type_id,
    int|string $entity_id,
    bool $deleted = FALSE
  ): void {
    if (is_string($entity_id)) {
      if (ctype_digit($entity_id)) {
        $entity_id = (int) $entity_id;
      }
      else {
        return;
      }
    }
    if ($deleted) {
      $this->purgeEntityUsage($entity_type_id, $entity_id);
    }
    else {
      $this->markEntityForScan($entity_type_id, $entity_id);
    }
  }

  /* -------------------------------------------------------------------------
   *  Internal helpers
   * ---------------------------------------------------------------------- */

  private function collectAllEntityIds(): array {
    $nids = $this->database->select('node_field_data', 'n')
      ->fields('n', ['nid'])->execute()->fetchCol();
    $bids = $this->database->select('block_content_field_data', 'b')
      ->fields('b', ['id'])->execute()->fetchCol();
    $tids = $this->database->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid'])->execute()->fetchCol();
    $cids = $this->database->select('comment_field_data', 'c')
      ->fields('c', ['cid'])->execute()->fetchCol();
    return [$nids, $bids, $tids, $cids];
  }

  private function collectStaleNodeIds(int $threshold): array {
    $query = $this->database->select('node_field_data', 'n');
    if ($this->statusHasEntityColumns) {
      $query->leftJoin('filelink_usage_scan_status', 's',
        'n.nid = s.entity_id AND s.entity_type = :t', [':t' => 'node']);
    }
    else {
      $query->leftJoin('filelink_usage_scan_status', 's',
        'n.nid = s.nid');
    }
    $query->fields('n', ['nid']);
    $or = $query->orConditionGroup()
      ->isNull('s.scanned')
      ->condition('s.scanned', $threshold, '<');
    $nids = $query->condition($or)->execute()->fetchCol();
    return [$nids, [], [], []];
  }

  private function purgeEntityUsage(string $etype, int $eid): void {
    $table   = $this->matchesHasEntityColumns
      ? 'filelink_usage_matches' : 'filelink_usage';
    $links   = [];

    if ($table === 'filelink_usage_matches') {
      $links = $this->database->select($table, 'f')
        ->fields('f', ['link'])
        ->condition('entity_type', $etype)
        ->condition('entity_id', $eid)
        ->execute()->fetchCol();
    }
    elseif ($etype === 'node') {
      $links = $this->database->select($table, 'f')
        ->fields('f', ['link'])
        ->condition('nid', $eid)
        ->execute()->fetchCol();
    }

    $file_ids = [];
    foreach ($links as $uri) {
      $file = $this->entityTypeManager->getStorage('file')
        ->loadByProperties(['uri' => $uri]);
      if ($file) {
        $file = reset($file);
        $usage = $this->fileUsage->listUsage($file);
        if (!empty($usage['filelink_usage'][$etype][$eid])) {
          $count = $usage['filelink_usage'][$etype][$eid];
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $etype, $eid);
          }
          $file_ids[] = $file->id();
        }
      }
    }

    // Remove tracking rows.
    if ($table === 'filelink_usage_matches') {
      $this->database->delete($table)
        ->condition('entity_type', $etype)
        ->condition('entity_id', $eid)
        ->execute();
    }
    elseif ($etype === 'node') {
      $this->database->delete($table)
        ->condition('nid', $eid)
        ->execute();
    }

    if ($file_ids) {
      $tags = array_map(fn(int $id) => "file:$id", array_unique($file_ids));
      \Drupal::service('cache_tags.invalidator')->invalidateTags($tags);
    }
  }

}
