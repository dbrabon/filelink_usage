<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Manages file link usage records and scheduled scanning operations.
 */
class FileLinkUsageManager {

  /**
   * The file usage service for recording file usages.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected FileUsageInterface $fileUsage;

  /**
   * The entity type manager for loading entities.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * Time service (not heavily used, but available for timestamps if needed).
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * Flag indicating if the scan status table has multi-entity support.
   *
   * @var bool
   */
  protected bool $statusHasEntityColumns;

  /**
   * Flag indicating if the matches table has multi-entity support.
   *
   * @var bool
   */
  protected bool $matchesHasEntityColumns;

  /**
   * Constructs a FileLinkUsageManager.
   */
  public function __construct(FileUsageInterface $fileUsage, EntityTypeManagerInterface $entityTypeManager, Connection $database, TimeInterface $time) {
    $this->fileUsage = $fileUsage;
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->time = $time;
    // Detect if the database schema has entity_type columns (for multi-entity support).
    $this->statusHasEntityColumns = $this->database->schema()->fieldExists('filelink_usage_scan_status', 'entity_type');
    $this->matchesHasEntityColumns = $this->database->schema()->fieldExists('filelink_usage_matches', 'entity_type');
  }

  /**
   * Schedule an entity for scanning (to be processed on cron).
   *
   * @param string $entity_type
   *   The entity type (e.g., 'node', 'block', 'taxonomy_term').
   * @param int $entity_id
   *   The entity ID to mark for scanning.
   */
  public function markEntityForScan(string $entity_type, int $entity_id): void {
    // Ensure we use 'block' as the type for custom blocks.
    $type = ($entity_type === 'block_content') ? 'block' : $entity_type;
    // Insert or update the scan status to mark this entity for scanning.
    $this->database->merge('filelink_usage_scan_status')
      ->key('entity_type', $type)
      ->key('entity_id', $entity_id)
      ->fields([
        'entity_type' => $type,
        'entity_id'   => $entity_id,
        'timestamp'   => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Execute pending scans for marked content (called during cron).
   */
  public function runCron(): void {
    $query = $this->database->select('filelink_usage_scan_status', 's')
      ->fields('s', ['entity_type', 'entity_id']);
    $results = $query->execute()->fetchAll();
    if (empty($results)) {
      // Nothing to scan.
      return;
    }
    $verbose = \Drupal::config('filelink_usage.settings')->get('verbose_logging');
    if ($verbose) {
      \Drupal::logger('filelink_usage')->notice('Cron scan started: @count content items to process.', ['@count' => count($results)]);
    }
    foreach ($results as $record) {
      $entity_type = $record->entity_type;
      $entity_id = $record->entity_id;
      // Load the entity (for custom blocks, stored as 'block' type, which is not a content entity type,
      // so we convert back to 'block_content' to load the actual block entity).
      $load_type = ($entity_type === 'block') ? 'block_content' : $entity_type;
      $storage = $this->entityTypeManager->getStorage($load_type);
      $entity = $storage ? $storage->load($entity_id) : NULL;
      if ($entity) {
        // Perform the scan of this entity for file links.
        \Drupal::service('filelink_usage.scanner')->scanEntity($entity);
      }
      // Remove the record from the scan queue regardless of whether entity was found.
      $this->database->delete('filelink_usage_scan_status')
        ->condition('entity_type', $entity_type)
        ->condition('entity_id', $entity_id)
        ->execute();
    }
    if ($verbose) {
      \Drupal::logger('filelink_usage')->notice('Cron scan completed: @count content items processed.', ['@count' => count($results)]);
    }
  }

  /**
   * Reconciles file usage records for a given content entity.
   *
   * If $deleted is TRUE, all usage for that entity will be removed immediately.
   * If $deleted is FALSE, the entity is marked for later scanning (on cron).
   *
   * @param string $entity_type_id
   *   The entity type (e.g., 'node', 'block', 'taxonomy_term', 'comment').
   * @param int $entity_id
   *   The entity ID.
   * @param bool $deleted
   *   TRUE if the entity was deleted (remove all usage now), FALSE if just updated.
   */
  public function reconcileEntityUsage(string $entity_type_id, int $entity_id, bool $deleted = FALSE): void {
    if ($deleted) {
      // Entity was deleted: remove all its file usage references immediately.
      $type = ($entity_type_id === 'block_content') ? 'block' : $entity_type_id;
      // Load all link references for this entity from our matches table.
      $select = $this->database->select('filelink_usage_matches', 'm')
        ->fields('m', ['link'])
        ->condition('entity_type', $type)
        ->condition('entity_id', $entity_id);
      $links = $select->execute()->fetchCol();
      if (!empty($links)) {
        $verbose = \Drupal::config('filelink_usage.settings')->get('verbose_logging');
        $removed_count = 0;
        foreach ($links as $uri) {
          // Try to load the file entity for this URI.
          $file = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
          $file = $file ? reset($file) : NULL;
          if ($file) {
            // Remove all usage entries for this file on this content.
            $usage = $this->fileUsage->listUsage($file);
            if (isset($usage['filelink_usage'][$type][$entity_id])) {
              $count = $usage['filelink_usage'][$type][$entity_id];
              // Decrement the usage count completely for this entity.
              while ($count-- > 0) {
                $this->fileUsage->delete($file, 'filelink_usage', $type, $entity_id);
              }
            }
            // Clear file static cache so updated usage counts are reflected.
            $this->entityTypeManager->getStorage('file')->resetCache([$file->id()]);
            $removed_count++;
          }
        }
        // Remove all match records for this entity.
        $this->database->delete('filelink_usage_matches')
          ->condition('entity_type', $type)
          ->condition('entity_id', $entity_id)
          ->execute();
        if ($verbose && $removed_count) {
          \Drupal::logger('filelink_usage')->notice('%type (ID %id) deleted: removed file usage references for @count file(s).', [
            '%type' => $type,
            '%id' => $entity_id,
            '@count' => $removed_count,
          ]);
        }
      }
      return;
    }
    // For updates (not deletions), mark the entity for scanning via cron.
    $this->markEntityForScan($entity_type_id, $entity_id);
  }

  /**
   * Remove all usage records for a given file (invoked when a file is deleted).
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity being removed.
   */
  public function removeFileUsage(FileInterface $file): void {
    // Remove any usage entries for this file recorded by our module.
    $usage = $this->fileUsage->listUsage($file);
    if (!empty($usage['filelink_usage'])) {
      foreach ($usage['filelink_usage'] as $used_entity_type => $entity_ids) {
        foreach ($entity_ids as $id => $count) {
          // Decrement all usage counts for this file on each referencing entity.
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $used_entity_type, $id);
          }
        }
      }
    }
    // Remove all records from our matches table for this file's URI.
    $this->database->delete('filelink_usage_matches')
      ->condition('link', $file->getFileUri())
      ->execute();
    // (No need to clear cache here, as the file is being deleted entirely.)
  }

  /**
   * If a new file is added, register usage for any content that was referencing it.
   *
   * This catches cases where content contained a hard-coded link to a file before
   * the file was actually uploaded to Drupal. Once the file is added, we add usage.
   *
   * @param \Drupal\file\FileInterface $file
   *   The newly added file.
   */
  public function addUsageForFile(FileInterface $file): void {
    // Only act on public file system files; private file links are handled by scans.
    if (strpos($file->getFileUri(), 'public://') !== 0) {
      return;
    }
    // Find any content links that pointed to this file's URI.
    $uri = $file->getFileUri();
    $query = $this->database->select('filelink_usage_matches', 'm')
      ->fields('m', ['entity_type', 'entity_id'])
      ->condition('link', $uri);
    $references = $query->execute()->fetchAll();
    if (empty($references)) {
      return;
    }
    $verbose = \Drupal::config('filelink_usage.settings')->get('verbose_logging');
    foreach ($references as $ref) {
      $entity_type = $ref->entity_type;
      $entity_id = $ref->entity_id;
      // Add a file usage entry for each referencing content item.
      $this->fileUsage->add($file, 'filelink_usage', $entity_type, $entity_id);
      // Clear file cache to update usage count.
      $this->entityTypeManager->getStorage('file')->resetCache([$file->id()]);
      if ($verbose) {
        \Drupal::logger('filelink_usage')->notice('File "@name" (ID @fid) added: content %type (ID %id) is now linked to this file (usage recorded).', [
          '@name' => $file->getFilename(),
          '@fid' => $file->id(),
          '%type' => $entity_type,
          '%id' => $entity_id,
        ]);
      }
    }
    // After adding usage, the matches table already contained these links (they were kept when file was missing),
    // so we do not remove them. They will continue to be tracked normally.
  }

}
