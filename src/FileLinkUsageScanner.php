<?php

namespace Drupal\filelink_usage;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Render\RendererInterface;

/**
 * Scans content for file links and updates file usage accordingly.
 */
class FileLinkUsageScanner {

  /**
   * The entity type manager for loading entities.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The file usage service.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected $fileUsage;

  /**
   * The renderer service for rendering entities to HTML.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a FileLinkUsageScanner service.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\file\FileUsage\FileUsageInterface $fileUsage
   *   The file usage service.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, Connection $database, FileUsageInterface $fileUsage, RendererInterface $renderer) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->fileUsage = $fileUsage;
    $this->renderer = $renderer;
  }

  /**
   * Scans a given content entity for file links and updates file usage.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity to scan.
   */
  public function scanEntity(EntityInterface $entity) {
    $entity_type = $entity->getEntityTypeId();
    $entity_id = $entity->id();

    // Retrieve previously recorded file usage for this entity from our tracking table.
    $prev_fids = [];
    $result = $this->database->select('filelink_usage_matches', 'fum')
      ->fields('fum', ['fid'])
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->execute();
    foreach ($result as $record) {
      $prev_fids[] = (int) $record->fid;
    }
    $prev_fids = array_unique($prev_fids);

    // Render the entity to HTML (full view mode by default) to capture all file links in its output.
    $view_builder = $this->entityTypeManager->getViewBuilder($entity_type);
    $build = $view_builder->view($entity, 'full');
    $rendered = $this->renderer->render($build);
    $output = (string) $rendered;

    // Find all file links (public or private) in the rendered HTML.
    $new_fids = [];
    if (preg_match_all('/(?:src|href)="([^"]*\\/(?:sites\\/default\\/files|system\\/files)\\/[^"]+)"/i', $output, $matches)) {
      $file_urls = $matches[1];
      // Track each file URL found, avoid duplicates in the same content.
      $found_uris = [];
      $file_storage = $this->entityTypeManager->getStorage('file');
      foreach ($file_urls as $file_url) {
        // Determine the Drupal stream URI from the URL.
        $uri = NULL;
        if (strpos($file_url, '/sites/default/files/') !== FALSE) {
          // Public file.
          $path = substr($file_url, strpos($file_url, '/sites/default/files/') + strlen('/sites/default/files/'));
          $uri = 'public://' . rawurldecode($path);
        }
        elseif (strpos($file_url, '/system/files/') !== FALSE) {
          // Private file.
          $path = substr($file_url, strpos($file_url, '/system/files/') + strlen('/system/files/'));
          $uri = 'private://' . rawurldecode($path);
        }
        if (!$uri || isset($found_uris[$uri])) {
          // Skip if we could not determine a URI or already processed this file link.
          continue;
        }
        $found_uris[$uri] = TRUE;

        // Load the file entity by URI.
        $files = $file_storage->loadByProperties(['uri' => $uri]);
        if (empty($files)) {
          // No managed file corresponds to this URI, skip it.
          continue;
        }
        /** @var \Drupal\file\FileInterface $file */
        $file = reset($files);
        $fid = $file->id();
        // Ensure each file is counted only once per entity.
        if (!isset($new_fids[$fid])) {
          $new_fids[$fid] = $fid;
        }
      }
    }
    $new_fids = array_unique(array_values($new_fids));

    // Determine which files have been added or removed since the last scan.
    $to_add = array_diff($new_fids, $prev_fids);
    $to_remove = array_diff($prev_fids, $new_fids);

    // If no changes in usage, skip any updates.
    if (empty($to_add) && empty($to_remove)) {
      return;
    }

    // Prepare file storage for loading files.
    $file_storage = $this->entityTypeManager->getStorage('file');

    // Remove usage records for files that are no longer present.
    foreach ($to_remove as $fid) {
      $file = $file_storage->load($fid);
      if ($file) {
        // Remove the file usage record for this entity under our module.
        $this->fileUsage->delete($file, 'filelink_usage', $entity_type, $entity_id);
      }
      // Delete the record from our matches table.
      $this->database->delete('filelink_usage_matches')
        ->condition('entity_type', $entity_type)
        ->condition('entity_id', $entity_id)
        ->condition('fid', $fid)
        ->execute();
      // Invalidate cache for the file so its "Used in" count is updated.
      Cache::invalidateTags(['file:' . $fid]);
    }

    // Add usage records for new files found in the content.
    foreach ($to_add as $fid) {
      $file = $file_storage->load($fid);
      if (!$file) {
        continue;
      }
      // Avoid duplicate entries by removing usage from other modules for this file and entity.
      $usage = $this->fileUsage->listUsage($file);
      foreach ($usage as $module => $usage_info) {
        if (!empty($usage_info[$entity_type][$entity_id])) {
          if ($module !== 'filelink_usage') {
            // Remove usage recorded by other modules for this same file and entity.
            $this->fileUsage->delete($file, $module, $entity_type, $entity_id);
          }
        }
      }
      // Record the file usage under the filelink_usage module.
      $this->fileUsage->add($file, 'filelink_usage', $entity_type, $entity_id);

      // Insert or update our tracking table with this file link usage.
      $this->database->merge('filelink_usage_matches')
        ->key([
          'entity_type' => $entity_type,
          'entity_id' => $entity_id,
          'fid' => $fid,
        ])
        ->fields([
          'uri' => $file->getFileUri(),
        ])
        ->execute();
      // Invalidate cache for the file to refresh its usage count display.
      Cache::invalidateTags(['file:' . $fid]);
    }
  }

  /**
   * Scans all content for file links (called via Cron or manually).
   */
  public function scanAllContent() {
    // Example: scan all nodes. This could be extended to other entity types.
    $node_storage = $this->entityTypeManager->getStorage('node');
    $nids = $node_storage->getQuery()->accessCheck(FALSE)->execute();
    if (!empty($nids)) {
      $nodes = $node_storage->loadMultiple($nids);
      foreach ($nodes as $node) {
        $this->scanEntity($node);
      }
    }
  }

}
