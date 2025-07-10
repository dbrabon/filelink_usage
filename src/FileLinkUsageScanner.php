<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\file\FileInterface;

/**
 * Scans content entities for hard-coded file links and updates usage records.
 */
class FileLinkUsageScanner {

  /**
   * The file usage service.
   *
   * @var \Drupal\file\FileUsage\FileUsageInterface
   */
  protected FileUsageInterface $fileUsage;

  /**
   * The entity type manager for loading and resetting entities.
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
   * Indicates if the matches table has multi-entity columns.
   *
   * @var bool
   */
  protected bool $matchesHasEntityColumns;

  /**
   * Constructs a FileLinkUsageScanner.
   */
  public function __construct(FileUsageInterface $fileUsage, EntityTypeManagerInterface $entityTypeManager, Connection $database) {
    $this->fileUsage = $fileUsage;
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->matchesHasEntityColumns = $this->database->schema()->fieldExists('filelink_usage_matches', 'entity_type');
  }

  /**
   * Scan a content entity for hard-coded file links and update file usage records.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity to scan (node, block_content, paragraph, etc.).
   */
  public function scanEntity(EntityInterface $entity): void {
    $entity_type = $entity->getEntityType()->id();
    // Determine the usage context type (convert block_content to 'block').
    $usage_type = ($entity_type === 'block_content') ? 'block' : $entity_type;
    $entity_id = $entity->id();
    $verbose = \Drupal::config('filelink_usage.settings')->get('verbose_logging');

    // 1. Gather all hard-coded file links present in this entity's text fields.
    $content_links = [];
    // Iterate over all fields of the entity to find formatted text fields.
    foreach ($entity->getFieldDefinitions() as $field_name => $field_def) {
      $field_type = $field_def->getType();
      // Consider long text or text with summary fields (which hold HTML content).
      if ($field_type === 'text_long' || $field_type === 'text_with_summary' || $field_type === 'string_long') {
        if (!$entity->hasField($field_name)) {
          continue;
        }
        $field = $entity->get($field_name);
        if ($field->isEmpty()) {
          continue;
        }
        foreach ($field->getValue() as $value) {
          if (empty($value['value'])) {
            continue;
          }
          $text = $value['value'];
          // Look for '/sites/default/files/...', possibly with domain, and 'public://' or 'private://'.
          // Match any occurrence of public:// or private:// URIs.
          if (strpos($text, 'public://') !== FALSE || strpos($text, 'private://') !== FALSE) {
            preg_match_all('/(?:public:\\/\\/|private:\\/\\/)[^"\'\\s<>]+/i', $text, $matches1);
            foreach ($matches1[0] as $uri) {
              $content_links[$uri] = TRUE;
            }
          }
          // Match any occurrence of file URLs in the HTML (sites/default/files).
          if (strpos($text, '/sites/default/files/') !== FALSE) {
            preg_match_all('/\\/sites\\/default\\/files\\/[^"\'\\s<>]+/i', $text, $matches2);
            foreach ($matches2[0] as $urlPath) {
              // Remove any trailing punctuation that might be captured (like '.' or ',' at sentence end).
              $urlPath = rtrim($urlPath, '.,)');
              // Construct the file URI from the URL path.
              $file_uri = 'public://' . preg_replace('/^\\/sites\\/default\\/files\\//', '', $urlPath);
              $content_links[$file_uri] = TRUE;
            }
          }
        }
      }
    }
    // Convert the keys to a list of unique URIs (the values are just placeholders).
    $new_links = array_keys($content_links);

    // 2. Load existing recorded links from the database for this entity.
    $select = $this->database->select('filelink_usage_matches', 'm')
      ->fields('m', ['link']);
    $select->condition('entity_type', $usage_type)
           ->condition('entity_id', $entity_id);
    $existing_links = $select->execute()->fetchCol();
    $existing_links = $existing_links ?: [];

    // Prepare sets for comparison.
    $old_set = array_flip($existing_links);
    $new_set = array_flip($new_links);

    // Determine which links to add and which to remove.
    $to_add = array_diff_key($new_set, $old_set);
    $to_remove = array_diff_key($old_set, $new_set);

    // 3. Add new file link usages.
    foreach (array_keys($to_add) as $uri) {
      // Insert a new record in matches table for this link.
      $this->database->insert('filelink_usage_matches')
        ->fields([
          'entity_type' => $usage_type,
          'entity_id'   => $entity_id,
          'link'        => $uri,
        ])
        ->execute();
      // Attempt to load a file entity for this URI.
      $file = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
      $file = $file ? reset($file) : NULL;
      if ($file instanceof FileInterface) {
        // Found a managed file for this link, record usage.
        $this->fileUsage->add($file, 'filelink_usage', $usage_type, $entity_id);
        // Clear file static cache to update usage count immediately.
        $this->entityTypeManager->getStorage('file')->resetCache([$file->id()]);
        if ($verbose) {
          \Drupal::logger('filelink_usage')->notice('%type (ID %id): found new file link "@filename" (File ID @fid) – usage recorded.', [
            '%type' => $usage_type,
            '%id' => $entity_id,
            '@filename' => $file->getFilename(),
            '@fid' => $file->id(),
          ]);
        }
      }
      else {
        // No managed file exists for this URI. We still track the link, but cannot record usage.
        if ($verbose) {
          \Drupal::logger('filelink_usage')->notice('%type (ID %id): found file link "%uri" with no matching file – usage not recorded (will monitor for file creation).', [
            '%type' => $usage_type,
            '%id' => $entity_id,
            '%uri' => $uri,
          ]);
        }
      }
    }

    // 4. Remove file link usages that are no longer present.
    foreach (array_keys($to_remove) as $uri) {
      // Remove the record from matches table.
      $this->database->delete('filelink_usage_matches')
        ->condition('entity_type', $usage_type)
        ->condition('entity_id', $entity_id)
        ->condition('link', $uri)
        ->execute();
      // If a managed file existed for this URI, decrement its usage count.
      $file = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $uri]);
      $file = $file ? reset($file) : NULL;
      if ($file instanceof FileInterface) {
        // Remove the usage entry for this content if it exists.
        $usage = $this->fileUsage->listUsage($file);
        if (isset($usage['filelink_usage'][$usage_type][$entity_id])) {
          $count = $usage['filelink_usage'][$usage_type][$entity_id];
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $usage_type, $entity_id);
          }
        }
        // Clear file cache to update usage count.
        $this->entityTypeManager->getStorage('file')->resetCache([$file->id()]);
        if ($verbose) {
          \Drupal::logger('filelink_usage')->notice('%type (ID %id): removed file link "@filename" (File ID @fid) – usage entry deleted.', [
            '%type' => $usage_type,
            '%id' => $entity_id,
            '@filename' => $file->getFilename(),
            '@fid' => $file->id(),
          ]);
        }
      }
      else {
        // The link was to a file that never existed or was already removed.
        if ($verbose) {
          \Drupal::logger('filelink_usage')->notice('%type (ID %id): removed file link "%uri" (no file entity existed).', [
            '%type' => $usage_type,
            '%id' => $entity_id,
            '%uri' => $uri,
          ]);
        }
      }
    }
    // If verbose logging is enabled, avoid duplicate messages on subsequent scans by only logging changes (handled above).
  }

}
