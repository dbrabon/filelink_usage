<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Manages file link usage records and cron scanning operations.
 */
class FileLinkUsageManager {

  protected Connection $database;
  protected ConfigFactoryInterface $configFactory;
  protected TimeInterface $time;
  protected FileLinkUsageScanner $scanner;
  protected FileUsageInterface $fileUsage;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileLinkUsageNormalizer $normalizer;

  /** @var bool */
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
    $this->database = $database;
    $this->configFactory = $configFactory;
    $this->time = $time;
    $this->scanner = $scanner;
    $this->fileUsage = $fileUsage;
    $this->entityTypeManager = $entityTypeManager;
    $this->normalizer = $normalizer;
    // Detect if DB schema has new entity_type columns.
    $this->statusHasEntityColumns = $this->database->schema()->fieldExists('filelink_usage_scan_status', 'entity_type');
    $this->matchesHasEntityColumns = $this->database->schema()->fieldExists('filelink_usage_matches', 'entity_type');
  }

  /**
   * Execute the cron scan for pending content.
   */
  public function runCron(): void {
    $config    = $this->configFactory->getEditable('filelink_usage.settings');
    $frequency = $config->get('scan_frequency');
    $last_scan = (int) $config->get('last_scan');
    $intervals = [
      'every'   => 0,
      'hourly'  => 3600,
      'daily'   => 86400,
      'weekly'  => 604800,
      'monthly' => 2592000,
      'yearly'  => 31536000,
    ];
    $interval  = $intervals[$frequency] ?? 31536000;
    $now       = $this->time->getRequestTime();

    // If we have no records yet, force a full scan at least once.
    $match_count = (int) $this->database->select('filelink_usage_matches')
      ->countQuery()
      ->execute()
      ->fetchField();
    $force_rescan = ($match_count === 0);

    // Check if a scan is due based on the interval (unless forced).
    if (!$force_rescan && $interval && ($last_scan + $interval > $now)) {
      return;
    }

    // Determine which content needs scanning.
    if (!$force_rescan) {
      // Find nodes not scanned yet or older than the interval.
      $threshold = $interval ? $now - $interval : $now;
      $query = $this->database->select('node_field_data', 'n');
      if ($this->statusHasEntityColumns) {
        $query->leftJoin('filelink_usage_scan_status', 's', 'n.nid = s.entity_id AND s.entity_type = :t', [':t' => 'node']);
      }
      else {
        $query->leftJoin('filelink_usage_scan_status', 's', 'n.nid = s.nid');
      }
      $query->fields('n', ['nid']);
      $or = $query->orConditionGroup()
        ->isNull('s.scanned')
        ->condition('s.scanned', $threshold, '<');
      $nids = $query->condition($or)->execute()->fetchCol();
      $block_ids = $term_ids = $comment_ids = [];
    }
    else {
      // Force full scan: get all content IDs.
      $nids = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid'])
        ->execute()
        ->fetchCol();
      $block_ids = $this->database->select('block_content_field_data', 'b')
        ->fields('b', ['id'])
        ->execute()
        ->fetchCol();
      $term_ids = $this->database->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid'])
        ->execute()
        ->fetchCol();
      $comment_ids = $this->database->select('comment_field_data', 'c')
        ->fields('c', ['cid'])
        ->execute()
        ->fetchCol();
    }

    // Perform the scan on all pending entities.
    $entities_to_scan = [];
    if (!empty($nids)) {
      $entities_to_scan['node'] = $nids;
    }
    if (!empty($block_ids)) {
      $entities_to_scan['block_content'] = $block_ids;
    }
    if (!empty($term_ids)) {
      $entities_to_scan['taxonomy_term'] = $term_ids;
    }
    if (!empty($comment_ids)) {
      $entities_to_scan['comment'] = $comment_ids;
    }
    if (!empty($entities_to_scan)) {
      $this->scanner->scan($entities_to_scan);
    }
    // Update the last scan timestamp.
    $config->set('last_scan', $now)->save();
  }

  /**
   * Marks an entity for rescan (public API if needed elsewhere).
   */
  public function markEntityForScan(string $entity_type_id, int $entity_id): void {
    // Insert or update scan status to indicate a rescan is needed (scanned = 0).
    if ($entity_type_id === 'node') {
      if ($this->statusHasEntityColumns) {
        $this->database->merge('filelink_usage_scan_status')
          ->keys(['entity_type' => 'node', 'entity_id' => $entity_id])
          ->fields(['scanned' => 0])
          ->execute();
      }
      else {
        // Legacy table without entity_type column.
        $this->database->merge('filelink_usage_scan_status')
          ->keys(['nid' => $entity_id])
          ->fields(['scanned' => 0])
          ->execute();
      }
    }
    else {
      // For other entity types, we currently only track usage at scan time.
      // (Could extend to store scan status per entity type as well.)
    }
  }

  /**
   * Removes usage records when a node is deleted.
   */
  public function cleanupNode(\Drupal\node\NodeInterface $node): void {
    $this->reconcileEntityUsage('node', $node->id(), TRUE);
  }

  /**
   * Removes usage records when a file is deleted.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file that was deleted.
   */
  public function removeFileUsage(FileInterface $file): void {
    // Remove all usage entries for this file from the file usage service.
    $usage = $this->fileUsage->listUsage($file);
    if (!empty($usage['filelink_usage'])) {
      // For each entity referencing this file via filelink_usage, remove all usage counts.
      foreach ($usage['filelink_usage'] as $entity_type => $ids) {
        foreach ($ids as $entity_id => $count) {
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $entity_type, $entity_id);
          }
        }
      }
    }
    // Remove all records from our matches table for this file.
    $delete = $this->database->delete('filelink_usage_matches');
    if ($this->matchesHasEntityColumns && $this->database->schema()->fieldExists('filelink_usage_matches', 'link')) {
      $delete->condition('link', $file->getFileUri());
    }
    else {
      // Legacy schema: only track by node (nid).
      $delete->condition('link', $file->getFileUri());
    }
    $delete->execute();
  }

  /**
   * Reconciles file usage records for an entity, removing or scheduling as needed.
   *
   * @param string $entity_type_id
   *   The entity type (e.g., 'node', 'block', 'taxonomy_term', 'comment').
   * @param int $entity_id
   *   The entity ID.
   * @param bool $deleted
   *   TRUE if the entity was deleted, FALSE if just updating.
   */
  public function reconcileEntityUsage(string $entity_type_id, int|string $entity_id, bool $deleted = FALSE): void {
    // Sanitize and validate $entity_id.
    if (!is_numeric($entity_id) || ((int) $entity_id) <= 0) {
      \Drupal::logger('filelink_usage')->warning('Invalid entity ID: @id for type: @type', [
        '@id' => $entity_id,
        '@type' => $entity_type_id,
      ]);
      return;
    }
    $entity_id = (int) $entity_id;
    
    if ($deleted) {
      // Remove all file usage entries for this entity and its file links.
      $table = $this->database->schema()->tableExists('filelink_usage_matches') ? 'filelink_usage_matches' : 'filelink_usage';
      $links = [];
      if ($table === 'filelink_usage_matches') {
        $links = $this->database->select('filelink_usage_matches', 'f')
          ->fields('f', ['link'])
          ->condition('entity_type', $entity_type_id)
          ->condition('entity_id', $entity_id)
          ->execute()
          ->fetchCol();
      }
      else {
        if ($entity_type_id === 'node') {
          $links = $this->database->select('filelink_usage', 'f')
            ->fields('f', ['link'])
            ->condition('nid', $entity_id)
            ->execute()
            ->fetchCol();
        }
      }
      foreach ($links as $link) {
        /** @var \Drupal\file\FileInterface $file */
        $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $link]);
        $file = $files ? reset($files) : NULL;
        if ($file) {
          $usage = $this->fileUsage->listUsage($file);
          if (!empty($usage['filelink_usage'][$entity_type_id][$entity_id])) {
            $count = $usage['filelink_usage'][$entity_type_id][$entity_id];
            while ($count-- > 0) {
              $this->fileUsage->delete($file, 'filelink_usage', $entity_type_id, $entity_id);
            }
          }
        }
      }
      if ($table === 'filelink_usage_matches') {
        $this->database->delete('filelink_usage_matches')
          ->condition('entity_type', $entity_type_id)
          ->condition('entity_id', $entity_id)
          ->execute();
      }
      else {
        if ($entity_type_id === 'node') {
          $this->database->delete('filelink_usage')
            ->condition('nid', $entity_id)
            ->execute();
        }
      }
      return;
    }
    // If not deleted, mark the entity for rescan (handled by cron).
    $this->markEntityForScan($entity_type_id, $entity_id);
  }

}
