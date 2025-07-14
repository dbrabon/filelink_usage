<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Database\Connection;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\filelink_usage\FileLinkUsageNormalizer;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\file\FileInterface;

/**
 * Scans content entities for hard-coded file links and updates file usage.
 */
class FileLinkUsageScanner {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected RendererInterface $renderer;
  protected Connection $database;
  protected FileUsageInterface $fileUsage;
  protected ConfigFactoryInterface $configFactory;
  protected TimeInterface $time;
  protected LoggerChannelInterface $logger;
  protected FileLinkUsageNormalizer $normalizer;
  protected bool $statusHasEntityColumns;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, RendererInterface $renderer, Connection $database, FileUsageInterface $fileUsage, ConfigFactoryInterface $configFactory, TimeInterface $time, LoggerChannelInterface $logger, FileLinkUsageNormalizer $normalizer) {
    $this->entityTypeManager = $entityTypeManager;
    $this->renderer = $renderer;
    $this->database = $database;
    $this->fileUsage = $fileUsage;
    $this->configFactory = $configFactory;
    $this->time = $time;
    $this->logger = $logger;
    $this->normalizer = $normalizer;
    $this->statusHasEntityColumns = $this->database->schema()
      ->fieldExists('filelink_usage_scan_status', 'entity_type');
  }

  /**
   * Scan a list of entity IDs for hard-coded file usage.
   *
   * @param array $entity_ids
   *   An array of entity IDs keyed by entity type.
   */
  public function scan(array $entity_ids): void {
    // Collect file IDs whose usage changes to invalidate cache tags once at end.
    $changedFileIds = [];
    foreach ($entity_ids as $entity_type => $ids) {
      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage($entity_type);
      foreach ($storage->loadMultiple($ids) as $entity) {
        $this->scanEntity($entity, $changedFileIds);
      }
    }
    // Invalidate file cache tags for all changed files in one operation.
    if (!empty($changedFileIds)) {
      $tags = array_map(fn($fid) => "file:$fid", array_unique($changedFileIds));
      $tags[] = 'entity_list:file';
      \Drupal::service('cache_tags.invalidator')->invalidateTags($tags);
    }
  }

  /**
   * Convenience wrapper to scan a single node.
   */
  public function scanNode(NodeInterface $node): void {
    $this->scan(['node' => [$node->id()]]);
  }

  /**
   * Populate the matches table for the provided entities without touching usage.
   *
   * @param array $entity_ids
   *   An array of entity IDs keyed by entity type.
   */
  public function scanPopulateTable(array $entity_ids): void {
    foreach ($entity_ids as $type => $ids) {
      $storage = $this->entityTypeManager->getStorage($type);
      foreach ($storage->loadMultiple($ids) as $entity) {
        $dummy = NULL;
        $this->scanEntity($entity, $dummy, FALSE);
      }
    }
  }

  /**
   * Ensure file_usage has entries for each stored link.
   *
   * @return int[]
   *   File IDs whose usage records were added.
   */
  public function scanRecordUsage(): array {
    $changed = [];
    if (!$this->database->schema()->tableExists('filelink_usage_matches')) {
      return $changed;
    }
    $records = $this->database->select('filelink_usage_matches', 'f')
      ->fields('f', ['entity_type', 'entity_id', 'link'])
      ->execute();
    foreach ($records as $row) {
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $row->link]);
      if (!$files) {
        continue;
      }
      /** @var \Drupal\file\FileInterface $file */
      $file = reset($files);
      if (!$this->usageExists((int) $file->id(), $row->entity_type, (int) $row->entity_id)) {
        $this->fileUsage->add($file, 'filelink_usage', $row->entity_type, $row->entity_id);
        $changed[] = $file->id();
      }
    }
    return array_values(array_unique($changed));
  }

  /**
   * Remove file_usage rows that no longer correspond to stored matches.
   *
   * @return int[]
   *   File IDs whose usage records were removed.
   */
  public function scanRemoveFalsePositives(): array {
    $changed = [];
    $query = $this->database->select('file_usage', 'fu')
      ->fields('fu', ['fid', 'type', 'id', 'count'])
      ->condition('module', 'filelink_usage');
    foreach ($query->execute() as $row) {
      /** @var \Drupal\file\FileInterface|null $file */
      $file = $this->entityTypeManager->getStorage('file')->load($row->fid);
      if (!$file) {
        continue;
      }
      $exists = $this->database->select('filelink_usage_matches', 'f')
        ->fields('f', ['id'])
        ->condition('entity_type', $row->type)
        ->condition('entity_id', $row->id)
        ->condition('link', $file->getFileUri())
        ->execute()
        ->fetchField();
      if (!$exists) {
        $count = (int) $row->count;
        while ($count-- > 0) {
          $this->fileUsage->delete($file, 'filelink_usage', $row->type, $row->id);
        }
        $changed[] = $row->fid;
      }
    }
    return array_values(array_unique($changed));
  }

  /**
   * Scan a single content entity for file links.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity to scan (node, custom block, taxonomy term, comment, etc).
   * @param array &$changedFileIds
   *   (optional) Reference to an array to collect IDs of files whose usage changes.
   */
  public function scanEntity(EntityInterface $entity, ?array &$changedFileIds = NULL, bool $updateUsage = TRUE): void {
    $entity_type = $entity->getEntityType()->id();
    // Only scan supported content entity types.
    if (!in_array($entity_type, ['node', 'block_content', 'taxonomy_term', 'comment'])) {
      return;
    }

    // Determine the key used for this entity type in usage records.
    $type_key = ($entity_type === 'block_content') ? 'block' : $entity_type;
    $old_links = [];
    if ($this->database->schema()->tableExists('filelink_usage_matches')) {
      $old_links = $this->database->select('filelink_usage_matches', 'f')
        ->fields('f', ['link'])
        ->condition('entity_type', $type_key)
        ->condition('entity_id', $entity->id())
        ->execute()
        ->fetchCol();
    }
    else {
      // Legacy table (Drupal 9.x upgrade scenario) without entity_type column.
      if ($entity_type === 'node') {
        $old_links = $this->database->select('filelink_usage', 'f')
          ->fields('f', ['link'])
          ->condition('nid', $entity->id())
          ->execute()
          ->fetchCol();
      }
    }
    $table = $this->database->schema()->tableExists('filelink_usage_matches') ? 'filelink_usage_matches' : 'filelink_usage';

    // 1. Render the entity and find all file URLs in its HTML.
    $view_builder = $this->entityTypeManager->getViewBuilder($entity_type);
    $build = $view_builder->view($entity, 'full');
    $html = (string) $this->renderer->renderInIsolation($build);
    preg_match_all('/(?:src|href)=(["\'])([^"\']*\\/(?:sites\\/default\\/files|system\\/files)\\/[^"\']+)\1/i', $html, $matches);
    $file_urls = $matches[2] ?? [];

    // Track found file URIs and avoid counting duplicates within this entity.
    $found_uris = [];
    $fid_counts = [];
    foreach ($file_urls as $url) {
      $file_uri = $this->normalizer->normalize($url);
      if (!str_starts_with($file_uri, 'public://') && !str_starts_with($file_uri, 'private://')) {
        continue;
      }
      // Check if there is a managed File entity for this URI.
      $fid = NULL;
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $file_uri]);
      if ($files) {
        /** @var \Drupal\file\FileInterface $file_entity */
        $file_entity = reset($files);
        $fid = $file_entity->id();
      }
      if (!$fid) {
        // Skip untracked file links (no File entity exists yet).
        continue;
      }
      // Record this file (prevent duplicate counts per entity).
      if (!isset($found_uris[$fid])) {
        $found_uris[$fid] = $file_uri;
        $fid_counts[$fid] = 1;
      }
      else {
        $fid_counts[$fid]++;
      }
    }

    // 2. If no file links are found now, remove any stale usage records.
    if (empty($found_uris)) {
      $changed = [];
      if (!empty($old_links)) {
        // Previously had file links, now none – remove all usage entries.
        foreach ($old_links as $old_link) {
          $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $old_link]);
          $file = $files ? reset($files) : NULL;
          if ($file && $updateUsage) {
            // Delete all usage records for this file–entity combination.
            $usage = $this->fileUsage->listUsage($file);
            if (!empty($usage['filelink_usage'][$type_key][$entity->id()])) {
              $count = $usage['filelink_usage'][$type_key][$entity->id()];
              while ($count-- > 0) {
                $this->fileUsage->delete($file, 'filelink_usage', $type_key, $entity->id());
              }
              $changed[] = $file->id();
            }
          }
          // Remove the record from our tracking table.
          if ($table === 'filelink_usage_matches') {
            $this->database->delete('filelink_usage_matches')
              ->condition('entity_type', $type_key)
              ->condition('entity_id', $entity->id())
              ->condition('link', $old_link)
              ->execute();
          }
          else {
            $this->database->delete('filelink_usage')
              ->condition('nid', $entity->id())
              ->condition('link', $old_link)
              ->execute();
          }
        }
      }
      // If any usage was removed, invalidate cache for those files.
      if (!empty($changed) && $updateUsage) {
        if ($changedFileIds !== NULL) {
          // Defer cache invalidation to parent scan() by collecting file IDs.
          foreach (array_unique($changed) as $fid) {
            $changedFileIds[] = $fid;
          }
        }
        else {
          // Single-entity scan: invalidate file cache tags immediately.
          $tags = array_map(fn($fid) => "file:$fid", array_unique($changed));
          \Drupal::service('cache_tags.invalidator')->invalidateTags($tags);
        }
      }
      $timestamp = $this->time->getRequestTime();
      if ($this->statusHasEntityColumns) {
        $this->database->merge('filelink_usage_scan_status')
          ->keys([
            'entity_type' => $entity_type,
            'entity_id' => $entity->id(),
          ])
          ->fields(['scanned' => $timestamp])
          ->execute();
      }
      elseif ($entity_type === 'node') {
        $this->database->merge('filelink_usage_scan_status')
          ->keys(['nid' => $entity->id()])
          ->fields(['scanned' => $timestamp])
          ->execute();
      }
      return;
    }

    // 3. Insert/update file link records and file usage for each found link.
    // Determine usage type for file_usage service (treat block_content as 'block').
    $target_type = match (TRUE) {
      $entity instanceof NodeInterface => 'node',
      $entity_type === 'block_content' => 'block',
      $entity_type === 'taxonomy_term' => 'taxonomy_term',
      $entity_type === 'comment' => 'comment',
      default => 'node',
    };

    $changed = [];
    foreach ($found_uris as $fid => $uri) {
      // Upsert a tracking record of this file link in our database.
      if ($table === 'filelink_usage_matches') {
        $this->database->merge('filelink_usage_matches')
          ->keys([
            'entity_type' => $target_type,
            'entity_id'   => $entity->id(),
            'link'        => $uri,
          ])
          ->fields(['timestamp' => $this->time->getCurrentTime()])
          ->execute();
      }
      else {
        // Legacy table without entity_type.
        $this->database->merge('filelink_usage')
          ->keys([
            'nid'  => $entity->id(),
            'link' => $uri,
          ])
          ->fields(['timestamp' => $this->time->getCurrentTime()])
          ->execute();
      }
      // Only add a file usage entry if this file was not previously recorded.
      if ($updateUsage && !in_array($uri, $old_links)) {
        /** @var \Drupal\file\FileInterface $file */
        $file = $this->entityTypeManager->getStorage('file')->load($fid);
        if ($file && !$this->usageExists((int) $file->id(), $target_type, $entity->id())) {
          $this->fileUsage->add($file, 'filelink_usage', $target_type, $entity->id());
          $changed[] = $fid;
        }
      }
    }

    // 4. Remove usage for any file links that were previously present but are now gone.
    foreach ($old_links as $old_link) {
      if (!in_array($old_link, $found_uris)) {
        // This link was tracked before but is no longer in the content.
        $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $old_link]);
        $file = $files ? reset($files) : NULL;
        if ($file && $updateUsage) {
          $usage = $this->fileUsage->listUsage($file);
          if (!empty($usage['filelink_usage'][$target_type][$entity->id()])) {
            $count = $usage['filelink_usage'][$target_type][$entity->id()];
            while ($count-- > 0) {
              $this->fileUsage->delete($file, 'filelink_usage', $target_type, $entity->id());
            }
            $changed[] = $file->id();
          }
        }
        // Remove the outdated link from the tracking table.
        if ($table === 'filelink_usage_matches') {
          $this->database->delete('filelink_usage_matches')
            ->condition('entity_type', $target_type)
            ->condition('entity_id', $entity->id())
            ->condition('link', $old_link)
            ->execute();
        }
        else {
          $this->database->delete('filelink_usage')
            ->condition('nid', $entity->id())
            ->condition('link', $old_link)
            ->execute();
        }
      }
    }

    // 5. Ensure no duplicate usage entries remain if the same file appears multiple times.
    if ($updateUsage) {
      foreach ($found_uris as $fid => $uri) {
        /** @var \Drupal\file\FileInterface $file */
        $file = $this->entityTypeManager->getStorage('file')->load($fid);
        if ($file) {
          $usage = $this->fileUsage->listUsage($file);
          if (!empty($usage['filelink_usage'][$target_type][$entity->id()])) {
            $count = $usage['filelink_usage'][$target_type][$entity->id()];
            if ($count > 1) {
              // More than one usage recorded for this file–entity (remove extras).
              $changed[] = $fid;
            }
            while ($count-- > 1) {
              $this->fileUsage->delete($file, 'filelink_usage', $target_type, $entity->id());
            }
          }
        }
      }
    }

    // 6. Log scan results (only if the 'verbose_logging' setting is enabled and
    //    something changed).
    $config = $this->configFactory->get('filelink_usage.settings');
    if ($config->get('verbose_logging')) {
      $eid = $entity->id();
      // Use entity label if available, otherwise bundle name for identification.
      $entity_label = $entity instanceof NodeInterface
        ? $entity->label()
        : ($entity_type === 'taxonomy_term' ? $entity->label() : $entity->bundle());
      $new_count = count($found_uris);
      $old_count = count($old_links);
      $message = '';
      if ($new_count === 0 && $old_count > 0) {
        $message = "Scanned {$entity_type} {$eid} ({$entity_label}): removed {$old_count} file link" . ($old_count === 1 ? '' : 's') . ".";
      }
      elseif ($new_count > 0 && $old_count === 0) {
        $message = "Scanned {$entity_type} {$eid} ({$entity_label}): found {$new_count} file link" . ($new_count === 1 ? '' : 's') . ".";
      }
      elseif ($new_count > $old_count) {
        $diff = $new_count - $old_count;
        $message = "Scanned {$entity_type} {$eid} ({$entity_label}): added {$diff} file link" . ($diff === 1 ? '' : 's') . " (now {$new_count} total).";
      }
      elseif ($new_count < $old_count) {
        $diff = $old_count - $new_count;
        $message = "Scanned {$entity_type} {$eid} ({$entity_label}): removed {$diff} file link" . ($diff === 1 ? '' : 's') . " (now {$new_count} remaining).";
      }
      else {
        // Same count, but links might have changed (replacements).
        $new_set = array_values($found_uris);
        sort($new_set);
        $old_sorted = $old_links;
        sort($old_sorted);
        if ($new_set !== $old_sorted) {
          $message = "Scanned {$entity_type} {$eid} ({$entity_label}): updated file link references (now {$new_count} total).";
        }
      }
      if (!empty($message)) {
        $this->logger->info($message);
      }
    }

    // Invalidate caches for any files whose usage count changed (if not already deferred).
    if ($updateUsage && !empty($changed)) {
      if ($changedFileIds !== NULL) {
        // Defer invalidation to batch flush in scan().
        foreach (array_unique($changed) as $fid) {
          $changedFileIds[] = $fid;
        }
      }
      else {
        // Standalone scan: immediately invalidate file cache tags.
        $tags = array_map(fn($fid) => "file:$fid", array_unique($changed));
        \Drupal::service('cache_tags.invalidator')->invalidateTags($tags);
      }
    }

    $timestamp = $this->time->getRequestTime();
    if ($this->statusHasEntityColumns) {
      $this->database->merge('filelink_usage_scan_status')
        ->keys([
          'entity_type' => $entity_type,
          'entity_id' => $entity->id(),
        ])
        ->fields(['scanned' => $timestamp])
        ->execute();
    }
    elseif ($entity_type === 'node') {
      $this->database->merge('filelink_usage_scan_status')
        ->keys(['nid' => $entity->id()])
        ->fields(['scanned' => $timestamp])
        ->execute();
    }
  }

  /**
   * Determine if usage already exists for the given mapping.
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

}
