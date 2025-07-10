<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\node\NodeInterface;

/**
 * Manages file usage records for hard-coded file links.
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

    $schema = $database->schema();
    // Determine if database tables have new entity reference columns.
    $this->statusHasEntityColumns = $schema->fieldExists('filelink_usage_scan_status', 'entity_id');
    $this->matchesHasEntityColumns = $schema->fieldExists('filelink_usage_matches', 'entity_type');
  }

  /**
   * Runs scheduled scans on cron, based on configured frequency.
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

    // Determine which nodes need scanning.
    if (!$force_rescan) {
      // Find nodes not scanned yet or older than the interval.
      $threshold = $interval ? $now - $interval : $now;
      $query = $this->database->select('node_field_data', 'n');
      if ($this->statusHasEntityColumns) {
        // Join with scan status table by entity reference.
        $query->leftJoin(
          'filelink_usage_scan_status',
          's',
          'n.nid = s.entity_id AND s.entity_type = :t',
          [':t' => 'node']
        );
      }
      else {
        // Legacy join on older scan_status without entity_type.
        $query->leftJoin('filelink_usage_scan_status', 's', 'n.nid = s.nid');
      }
      $query->fields('n', ['nid']);
      $or = $query->orConditionGroup()
        ->isNull('s.scanned')
        ->condition('s.scanned', $threshold, '<');
      $nids = $query->condition($or)->execute()->fetchCol();
    }
    else {
      // Force full scan: get all node IDs.
      $nids = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid'])
        ->execute()
        ->fetchCol();
    }

    // Perform the scan and reconcile usage for each affected node.
    if (!empty($nids)) {
      $this->scanner->scan($nids);
      foreach ($nids as $nid) {
        $this->reconcileNodeUsage((int) $nid);
      }
    }
    // Update the last scan timestamp.
    $config->set('last_scan', $now)->save();
  }

  /**
   * Marks a node for rescan (public API for other parts of the module).
   */
  public function markEntityForScan(string $entity_type_id, int $entity_id): void {
    // Insert or update scan status to indicate a rescan is needed (scanned = 0).
    if ($entity_type_id === 'node') {
      if ($this->statusHasEntityColumns) {
        $this->database->merge('filelink_usage_scan_status')
          ->key(['entity_type' => 'node', 'entity_id' => $entity_id])
          ->fields(['scanned' => 0])
          ->execute();
      }
      else {
        // Legacy: key on nid for older schema.
        $this->database->merge('filelink_usage_scan_status')
          ->key(['nid' => $entity_id])
          ->fields(['scanned' => 0])
          ->execute();
      }
    }
  }

  /**
   * Reconcile (synchronize) usage records for a node, optionally on deletion.
   *
   * @param int $nid
   *   Node ID.
   * @param bool $deleted
   *   TRUE if the node was deleted (remove all its usage records).
   */
  public function reconcileNodeUsage(int $nid, bool $deleted = FALSE): void {
    $this->reconcileEntityUsage('node', $nid, $deleted);
  }

  /**
   * Cleans up usage records when a node is deleted.
   *
   * @param \Drupal\node\NodeInterface $node
   *   The node that was deleted.
   */
  public function cleanupNode(NodeInterface $node): void {
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
      // For each entity referencing this file via filelink_usage, remove the usage.
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
    if ($this->matchesHasEntityColumns && $this->database->schema()->fieldExists('filelink_usage_matches', 'fid')) {
      // If the matches table has a fid column (new schema), use it.
      $delete->condition('fid', $file->id());
    }
    else {
      // Otherwise, use the URI/link field to identify records.
      $delete->condition($this->matchesHasEntityColumns && $this->database->schema()->fieldExists('filelink_usage_matches', 'uri') ? 'uri' : 'link', $file->getFileUri());
    }
    $delete->execute();
  }

  /**
   * Reconcile usage records for a given content entity (generic helper).
   *
   * @param string $entity_type_id
   *   Entity type (e.g., 'node').
   * @param int $entity_id
   *   Entity ID.
   * @param bool $deleted
   *   TRUE if the entity was deleted (remove all usage).
   */
  public function reconcileEntityUsage(string $entity_type_id, int $entity_id, bool $deleted = FALSE): void {
    // If entity was deleted, remove all its usage entries.
    if ($deleted) {
      $this->database->delete('filelink_usage_matches')
        ->condition('entity_type', $entity_type_id)
        ->condition('entity_id', $entity_id)
        ->execute();
      return;
    }
    // Otherwise, mark entity for rescan and let cron handle reconciliation.
    $this->markEntityForScan($entity_type_id, $entity_id);
  }

  /**
   * When a new file is added, immediately add usage records if it was referenced.
   *
   * @param \Drupal\file\FileInterface $file
   *   The newly added file.
   */
  public function addUsageForFile(FileInterface $file): void {
    // Only consider public files (private file references are handled via scanning).
    if (strpos($file->getFileUri(), 'public://') !== 0) {
      return;
    }
    // Check if any content had a link pointing to this file's URI.
    $query = $this->database->select('filelink_usage_matches', 'f')
      ->condition($this->matchesHasEntityColumns ? 'link' : 'link', $file->getFileUri());
    if ($this->matchesHasEntityColumns) {
      // New schema: we have entity_type and entity_id stored.
      $query->fields('f', ['entity_type', 'entity_id']);
      $records = $query->execute()->fetchAll();
      foreach ($records as $record) {
        // If a content entity was referencing this file, add a usage entry for it.
        $this->fileUsage->add($file, 'filelink_usage', $record->entity_type, $record->entity_id);
      }
    }
    else {
      // Legacy schema (no entity_type column): only nodes were tracked.
      $query->fields('f', ['link']);
      $nids = $query->execute()->fetchCol();
      foreach ($nids as $nid) {
        $this->fileUsage->add($file, 'filelink_usage', 'node', $nid);
      }
    }
  }

}
