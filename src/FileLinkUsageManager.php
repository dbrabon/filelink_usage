<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\file\FileInterface;
use Drupal\filelink_usage\FileLinkUsageFileFinder;
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
  protected FileLinkUsageFileFinder $fileFinder;
  protected CacheTagsInvalidatorInterface $cacheTagsInvalidator;
  protected bool $statusHasEntityColumns;
  protected bool $matchesHasEntityColumns;

  public function __construct(
    Connection $database,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    FileLinkUsageScanner $scanner,
    FileUsageInterface $fileUsage,
    EntityTypeManagerInterface $entityTypeManager,
    FileLinkUsageNormalizer $normalizer,
    FileLinkUsageFileFinder $fileFinder,
    CacheTagsInvalidatorInterface $cacheTagsInvalidator
  ) {
    $this->database            = $database;
    $this->configFactory       = $configFactory;
    $this->time                = $time;
    $this->scanner             = $scanner;
    $this->fileUsage           = $fileUsage;
    $this->entityTypeManager   = $entityTypeManager;
    $this->normalizer          = $normalizer;
    $this->fileFinder          = $fileFinder;
    $this->cacheTagsInvalidator = $cacheTagsInvalidator;

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
    // Check for stored link references to this file and register usage for
    // each referencing entity. Older schema versions may not have the matches
    // table, so guard against that scenario.
    if ($this->database->schema()->tableExists('filelink_usage_matches')) {
      $normalized = $this->normalizer->normalize($file->getFileUri());
      $query = $this->database->select('filelink_usage_matches', 'f')
        ->fields('f', ['entity_type', 'entity_id']);
      $or = $query->orConditionGroup()
        ->condition('managed_file_uri', $file->getFileUri())
        ->condition('link', $normalized);
      $query->condition($or);

      foreach ($query->execute() as $row) {
        $type = $row->entity_type;
        $id   = (int) $row->entity_id;
        if (!$this->usageExists((int) $file->id(), $type, $id)) {
          $this->fileUsage->add($file, 'filelink_usage', $type, $id);
        }
      }
    }

    // Invalidate the file's cache tag so any usage displays update promptly.
    $this->cacheTagsInvalidator->invalidateTags(['file:' . $file->id()]);
  }

  /**
   * Execute cron scan for entities needing re‑scan.
   */
  public function runCron(): void {
    // Skip if the module schema has not been installed yet.
    if (!$this->database->schema()->tableExists('filelink_usage_matches')) {
      return;
    }
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
    [$nids, $block_ids, $term_ids, $comment_ids, $paragraph_ids] = $needs_full
      ? $this->collectAllEntityIds()
      : $this->collectStaleEntityIds($now - $interval);

    $entities = [];
    if ($nids)        { $entities['node']          = $nids; }
    if ($block_ids)   { $entities['block_content'] = $block_ids; }
    if ($term_ids)    { $entities['taxonomy_term'] = $term_ids; }
    if ($comment_ids) { $entities['comment']       = $comment_ids; }
    if ($paragraph_ids) { $entities['paragraph'] = $paragraph_ids; }

    $changed = [];
    if ($entities) {
      $this->scanner->scanPopulateTable($entities);
      $changed = array_merge($changed, $this->scanner->scanRecordUsage());
      $changed = array_merge($changed, $this->scanner->scanRemoveFalsePositives());
    }

    if (!empty($changed)) {
      $tags = array_map(fn(int $fid) => "file:$fid", array_unique($changed));
      $this->cacheTagsInvalidator->invalidateTags($tags);
    }

    $config->set('last_scan', $now)->save();
  }

  /**
   * Mark an entity for re‑scan (e.g. from hook_entity_update()).
   */
  public function markEntityForScan(string $entity_type_id, int $entity_id): void {
    $supported = ['node', 'block_content', 'taxonomy_term', 'comment'];

    if ($this->statusHasEntityColumns) {
      if (in_array($entity_type_id, $supported, TRUE)) {
        $this->database->merge('filelink_usage_scan_status')
          ->keys([
            'entity_type' => $entity_type_id,
            'entity_id' => $entity_id,
          ])
          ->fields(['scanned' => 0])
          ->execute();
      }
    }
    else {
      if ($entity_type_id === 'node') {
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
    // Previous versions removed file usage entries when a file entity was
    // deleted. This cleanup step has been disabled to preserve historical
    // references. The method remains for API compatibility.
  }

  /**
   * Manually manage usage records for a set of file URIs.
   *
   * @param string $entity_type
   *   The entity type owning the links (e.g. 'node').
   * @param int $entity_id
   *   The entity ID.
   * @param array $uris
   *   List of file URIs to register. URIs without a matching File entity are
   *   ignored and no tracking row is created.
   */
  public function manageUsage(string $entity_type, int $entity_id, ?array $uris): void {
    if ($uris === NULL) {
      return;
    }
    $table = $this->matchesHasEntityColumns
      ? 'filelink_usage_matches' : 'filelink_usage';

    $target_type = $entity_type === 'block_content' ? 'block' : $entity_type;

    $start = $this->time->getRequestTime();

    $normalized_uris = [];
    foreach ($uris as $uri) {
      $normalized_uris[] = $this->normalizer->normalize($uri);
    }
    $normalized_uris = array_values(array_unique($normalized_uris));

    if ($normalized_uris === []) {
      return;
    }

    $file_ids = [];
    foreach ($normalized_uris as $uri) {
      $file = $this->fileFinder->loadFileByNormalizedUri($uri);
      if ($file) {
        if (!$this->usageExists((int) $file->id(), $target_type, $entity_id)) {
          $this->fileUsage->add($file, 'filelink_usage', $target_type, $entity_id);
          $file_ids[] = $file->id();
        }

        if ($table === 'filelink_usage_matches') {
          $this->database->merge($table)
            ->keys([
              'entity_type' => $target_type,
              'entity_id' => $entity_id,
              'link' => $uri,
            ])
            ->fields([
              'timestamp' => $start,
              'managed_file_uri' => $file->getFileUri(),
            ])
            ->execute();
        }
        elseif ($entity_type === 'node') {
          $this->database->merge($table)
            ->keys([
              'nid' => $entity_id,
              'link' => $uri,
            ])
            ->fields(['timestamp' => $start])
            ->execute();
        }
      }
    }

    $stale_query = NULL;
    if ($table === 'filelink_usage_matches') {
      $stale_query = $this->database->select($table, 'f')
        ->fields('f', ['id', 'link', 'managed_file_uri'])
        ->condition('entity_type', $target_type)
        ->condition('entity_id', $entity_id)
        ->condition('timestamp', $start, '<');
    }
    elseif ($entity_type === 'node') {
      $stale_query = $this->database->select($table, 'f')
        ->fields('f', ['id', 'link'])
        ->condition('nid', $entity_id)
        ->condition('timestamp', $start, '<');
    }

    if ($stale_query) {
      foreach ($stale_query->execute() as $row) {
        $uri = $row->link;
        $managed = $row->managed_file_uri;
        $this->database->delete($table)
          ->condition('id', $row->id)
          ->execute();

        $file = NULL;
        if ($managed) {
          $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $managed]);
          if ($files) {
            $file = reset($files);
          }
        }
        if (!$file) {
          $file = $this->fileFinder->loadFileByNormalizedUri($uri);
        }
        if ($file) {
          $usage = $this->fileUsage->listUsage($file);
          if (!empty($usage['filelink_usage'][$target_type][$entity_id])) {
            $count = $usage['filelink_usage'][$target_type][$entity_id];
            while ($count-- > 0) {
              $this->fileUsage->delete($file, 'filelink_usage', $target_type, $entity_id);
            }
            $file_ids[] = $file->id();
          }
        }
      }
    }

    if ($file_ids) {
      $tags = array_map(fn(int $id) => "file:$id", array_unique($file_ids));
      $tags[] = 'file_list';
      $this->cacheTagsInvalidator->invalidateTags($tags);
    }
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
    $nids = [];
    if ($this->database->schema()->tableExists('node_field_data')) {
      $nids = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid'])->execute()->fetchCol();
    }

    $bids = [];
    if ($this->database->schema()->tableExists('block_content_field_data')) {
      $bids = $this->database->select('block_content_field_data', 'b')
        ->fields('b', ['id'])->execute()->fetchCol();
    }

    $tids = [];
    if ($this->database->schema()->tableExists('taxonomy_term_field_data')) {
      $tids = $this->database->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid'])->execute()->fetchCol();
    }

    $cids = [];
    if ($this->database->schema()->tableExists('comment_field_data')) {
      $cids = $this->database->select('comment_field_data', 'c')
        ->fields('c', ['cid'])->execute()->fetchCol();
    }

    $pids = [];
    if (\Drupal::service('entity_type.manager')->hasDefinition('paragraph') &&
        $this->database->schema()->tableExists('paragraph')) {
      $pids = $this->database->select('paragraph', 'p')
        ->fields('p', ['id'])->execute()->fetchCol();
    }
    return [$nids, $bids, $tids, $cids, $pids];
  }

  private function collectStaleEntityIds(int $threshold): array {
    if ($this->statusHasEntityColumns) {
      $map = [
        'node' => ['node_field_data', 'nid'],
        'block_content' => ['block_content_field_data', 'id'],
        'taxonomy_term' => ['taxonomy_term_field_data', 'tid'],
        'comment' => ['comment_field_data', 'cid'],
      ];

      if (\Drupal::service('entity_type.manager')->hasDefinition('paragraph') &&
          $this->database->schema()->tableExists('paragraph')) {
        $map['paragraph'] = ['paragraph', 'id'];
      }

      $results = [];
      foreach ($map as $type => [$table, $id_field]) {
        if ($this->database->schema()->tableExists($table)) {
          $query = $this->database->select($table, 'e');
          $query->leftJoin('filelink_usage_scan_status', 's',
            "e.$id_field = s.entity_id AND s.entity_type = :t", [':t' => $type]);
          $query->fields('e', [$id_field]);
          $or = $query->orConditionGroup()
            ->isNull('s.scanned')
            ->condition('s.scanned', $threshold, '<');
          $results[$type] = $query->condition($or)->execute()->fetchCol();
        }
        else {
          $results[$type] = [];
        }
      }

      return [
        $results['node'],
        $results['block_content'],
        $results['taxonomy_term'],
        $results['comment'],
        $results['paragraph'] ?? [],
      ];
    }
    else {
      $query = $this->database->select('node_field_data', 'n');
      $query->leftJoin('filelink_usage_scan_status', 's', 'n.nid = s.nid');
      $query->fields('n', ['nid']);
      $or = $query->orConditionGroup()
        ->isNull('s.scanned')
        ->condition('s.scanned', $threshold, '<');
      $nids = $query->condition($or)->execute()->fetchCol();
      return [$nids, [], [], [], []];
    }
  }

  private function purgeEntityUsage(string $etype, int $eid): void {
    $table   = $this->matchesHasEntityColumns
      ? 'filelink_usage_matches' : 'filelink_usage';
    $links   = [];

    if ($table === 'filelink_usage_matches') {
      $result = $this->database->select($table, 'f')
        ->fields('f', ['link', 'managed_file_uri'])
        ->condition('entity_type', $etype)
        ->condition('entity_id', $eid)
        ->execute();
      foreach ($result as $rec) {
        $links[] = [$rec->link, $rec->managed_file_uri];
      }
    }
    elseif ($etype === 'node') {
      $links = $this->database->select($table, 'f')
        ->fields('f', ['link'])
        ->condition('nid', $eid)
        ->execute()->fetchCol();
    }

    $file_ids = [];
    foreach ($links as $entry) {
      [$uri, $managed] = is_array($entry) ? $entry : [$entry, NULL];
      $file = NULL;
      if ($managed) {
        $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $managed]);
        if ($files) {
          $file = reset($files);
        }
      }
      if (!$file) {
        $file = $this->fileFinder->loadFileByNormalizedUri($uri);
      }
      if ($file) {
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

    // Remove scan status entry for this entity.
    if ($this->statusHasEntityColumns) {
      $this->database->delete('filelink_usage_scan_status')
        ->condition('entity_type', $etype)
        ->condition('entity_id', $eid)
        ->execute();
    }
    elseif ($etype === 'node') {
      $this->database->delete('filelink_usage_scan_status')
        ->condition('nid', $eid)
        ->execute();
    }

    if ($file_ids) {
      $tags = array_map(fn(int $id) => "file:$id", array_unique($file_ids));
      $tags[] = 'file_list';
      $this->cacheTagsInvalidator->invalidateTags($tags);
    }
  }

  /**
   * Check if a file_usage row already exists for the given mapping.
   */
  private function usageExists(int $fid, string $type, int $id): bool {
    return (bool) $this->database->select('file_usage', 'fu')
      ->condition('fid', $fid)
      ->condition('module', 'filelink_usage')
      ->condition('type', $type)
      ->condition('id', $id)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Load a managed file by its normalized URI.
   */
  public function loadFileByNormalizedUri(string $uri): ?FileInterface {
    return $this->fileFinder->loadFileByNormalizedUri($uri);
  }

}
