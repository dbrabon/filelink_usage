<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Datetime\TimeInterface;
use Psr\Log\LoggerInterface;

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
  protected LoggerInterface $logger;

  /**
   * Constructs the scanner.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    RendererInterface $renderer,
    Connection $database,
    FileUsageInterface $fileUsage,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    LoggerInterface $logger,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->renderer = $renderer;
    $this->database = $database;
    $this->fileUsage = $fileUsage;
    $this->configFactory = $configFactory;
    $this->time = $time;
    $this->logger = $logger;
  }

  /**
   * Scan a list of entity IDs for hard‑coded file usage.
   *
   * @param array $entity_ids
   *   An array of entity IDs keyed by entity type.
   */
  public function scan(array $entity_ids): void {
    foreach ($entity_ids as $entity_type => $ids) {
      /** @var \Drupal\Core\Entity\ContentEntityStorageInterface $storage */
      $storage = $this->entityTypeManager->getStorage($entity_type);
      foreach ($storage->loadMultiple($ids) as $entity) {
        $this->scanEntity($entity);
      }
    }
  }

  /**
   * Convenience wrapper to scan a single node.
   */
  public function scanNode(NodeInterface $node): void {
    $this->scan(['node' => [$node->id()]]);
  }

  /**
   * Scan a single content entity for file links.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The content entity to scan (node, custom block, taxonomy term, comment, etc).
   */
  public function scanEntity(EntityInterface $entity): void {
    $entity_type = $entity->getEntityType()->id();
    // Only scan supported content entity types (node, block_content, taxonomy_term, comment).
    if (!in_array($entity_type, ['node', 'block_content', 'taxonomy_term', 'comment'])) {
      return;
    }

    // Retrieve any previously recorded file links for this entity.
    $type_key = $entity_type;
    if ($entity_type === 'block_content') {
      $type_key = 'block';
    }
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
      // Legacy table without entity_type column (only nodes tracked).
      if ($entity_type === 'node') {
        $old_links = $this->database->select('filelink_usage', 'f')
          ->fields('f', ['link'])
          ->condition('nid', $entity->id())
          ->execute()
          ->fetchCol();
      }
    }
    $table = $this->database->schema()->tableExists('filelink_usage_matches') ? 'filelink_usage_matches' : 'filelink_usage';

    /* 1. Render the entity and find all file URLs in its HTML */
    $view_builder = $this->entityTypeManager->getViewBuilder($entity_type);
    // Render the entity in the 'full' view mode to get HTML as a string.
    $html = (string) $this->renderer->renderPlain($view_builder->view($entity, 'full'));
    // Use regex to capture file links (public:// or private:// paths in src/href).
    preg_match_all(
      '/(?:src|href)="([^"]*\\/(?:sites\\/default\\/files|system\\/files)\\/[^"]+)"/i',
      $html,
      $matches
    );
    // Extract the file URLs (if any) from regex matches.
    $file_urls = $matches[1] ?? [];

    $found_uris = [];
    $fid_counts = [];
    foreach ($file_urls as $url) {
      $uri = NULL;
      if (strpos($url, '/sites/default/files/') !== FALSE) {
        // Convert relative to full URL by prefixing site base if needed.
        $uri = \Drupal::request()->getSchemeAndHttpHost() . $url;
      }
      elseif (strpos($url, '/system/files/') !== FALSE || strpos($url, '://') !== FALSE) {
        // Already an absolute URL or stream (e.g., private://).
        $uri = $url;
      }
      if (!$uri) {
        continue;
      }
      // Standardize to public:// or private:// scheme if possible.
      $file_uri = preg_replace('/^https?:\\/\\//', '', $uri);
      if ($file_uri && str_contains($file_uri, '/sites/default/files/')) {
        $file_uri = 'public://' . explode('/sites/default/files/', $file_uri, 2)[1];
      }
      elseif ($file_uri && str_contains($file_uri, '/system/files/')) {
        $file_uri = 'private://' . explode('/system/files/', $file_uri, 2)[1];
      }
      else {
        continue;
      }
      // Check if a managed File entity exists with this URI.
      $fid = NULL;
      $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $file_uri]);
      if ($files) {
        /** @var \Drupal\file\FileInterface $file_entity */
        $file_entity = reset($files);
        $fid = $file_entity->id();
      }
      if (!$fid) {
        // No matching file found for this link, skip it.
        continue;
      }
      // Track this file reference (avoid duplicate counting).
      if (!isset($found_uris[$fid])) {
        $found_uris[$fid] = $file_uri;
        $fid_counts[$fid] = 1;
      }
      else {
        $fid_counts[$fid]++;
      }
    }

    // 2. If no file links found, remove any stale usage records for this entity.
    if (empty($found_uris)) {
      if (!empty($old_links)) {
        // Remove usage for all previously found links since none remain.
        foreach ($old_links as $old_link) {
          $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $old_link]);
          $file = $files ? reset($files) : NULL;
          if ($file) {
            // Delete all usage entries for this entity-file combination.
            $usage = $this->fileUsage->listUsage($file);
            if (!empty($usage['filelink_usage'][$type_key][$entity->id()])) {
              $count = $usage['filelink_usage'][$type_key][$entity->id()];
              while ($count-- > 0) {
                $this->fileUsage->delete($file, 'filelink_usage', $type_key, $entity->id());
              }
            }
          }
          // Remove the stale database record.
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
      return;
    }

    // 3. Insert or update database records and file usage for each found file link.
    $target_type = 'node';
    if ($entity instanceof NodeInterface) {
      $target_type = 'node';
    }
    elseif ($entity_type === 'block_content') {
      // For custom blocks, treat usage type as 'block'.
      $target_type = 'block';
    }
    elseif ($entity_type === 'taxonomy_term') {
      $target_type = 'taxonomy_term';
    }
    elseif ($entity_type === 'comment') {
      $target_type = 'comment';
    }
    foreach ($found_uris as $fid => $uri) {
      // Insert or update the record of this file link usage in the database.
      if ($table === 'filelink_usage_matches') {
        $this->database->merge('filelink_usage_matches')
          ->keys([
            'entity_type' => $target_type,
            'entity_id' => $entity->id(),
            'link' => $uri,
          ])
          ->fields([
            'timestamp' => $this->time->getCurrentTime(),
          ])
          ->execute();
      }
      else {
        // Legacy table without entity_type.
        $this->database->merge('filelink_usage')
          ->keys([
            'nid' => $entity->id(),
            'link' => $uri,
          ])
          ->fields([
            'timestamp' => $this->time->getCurrentTime(),
          ])
          ->execute();
      }
      // Only add a file usage entry if this link was not already recorded for this entity.
      if (!in_array($uri, $old_links)) {
        /** @var \Drupal\file\FileInterface $file */
        $file = $this->entityTypeManager->getStorage('file')->load($fid);
        if ($file) {
          $this->fileUsage->add($file, 'filelink_usage', $target_type, $entity->id());
        }
      }
    }

    // 4. Remove usage for any previously matched files on this entity that are now gone.
    foreach ($old_links as $old_link) {
      if (!in_array($old_link, $found_uris)) {
        // This previously recorded link is no longer present.
        $files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $old_link]);
        $file = $files ? reset($files) : NULL;
        if ($file) {
          // Delete file usage entries for this entity.
          $usage = $this->fileUsage->listUsage($file);
          if (!empty($usage['filelink_usage'][$target_type][$entity->id()])) {
            $count = $usage['filelink_usage'][$target_type][$entity->id()];
            while ($count-- > 0) {
              $this->fileUsage->delete($file, 'filelink_usage', $target_type, $entity->id());
            }
          }
        }
        // Remove the stale database record.
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

    // 5. Ensure no duplicate file usage entries remain for links still present multiple times.
    foreach ($found_uris as $fid => $uri) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if ($file) {
        $usage = $this->fileUsage->listUsage($file);
        if (!empty($usage['filelink_usage'][$target_type][$entity->id()])) {
          $count = $usage['filelink_usage'][$target_type][$entity->id()];
          while ($count-- > 1) {
            $this->fileUsage->delete($file, 'filelink_usage', $target_type, $entity->id());
          }
        }
      }
    }

    // 6. Log scan results if verbose logging is enabled (only log on changes).
    $config = $this->configFactory->get('filelink_usage.settings');
    if ($config->get('verbose')) {
      $eid = $entity->id();
      $entity_label = $entity instanceof NodeInterface ? $entity->label() : ($entity_type === 'taxonomy_term' ? $entity->label() : $entity->bundle());
      $new_count = count($found_uris);
      $old_count = count($old_links);
      $message = '';
      if ($new_count === 0 && $old_count > 0) {
        // All links were removed.
        $message = "Scanned {$entity_type} {$eid} ($entity_label): removed $old_count file link" . ($old_count === 1 ? '' : 's') . ".";
      }
      elseif ($new_count > 0 && $old_count === 0) {
        // New links were found in previously empty content.
        $message = "Scanned {$entity_type} {$eid} ($entity_label): found $new_count file link" . ($new_count === 1 ? '' : 's') . ".";
      }
      elseif ($new_count > $old_count) {
        $diff = $new_count - $old_count;
        $message = "Scanned {$entity_type} {$eid} ($entity_label): added $diff file link" . ($diff === 1 ? '' : 's') . " (now $new_count total).";
      }
      elseif ($new_count < $old_count) {
        $diff = $old_count - $new_count;
        $message = "Scanned {$entity_type} {$eid} ($entity_label): removed $diff file link" . ($diff === 1 ? '' : 's') . " (now $new_count remaining).";
      }
      else {
        // Count is the same, but check if different links (replacements).
        $new_set = array_values($found_uris);
        sort($new_set);
        $old_sorted = $old_links;
        sort($old_sorted);
        if ($new_set !== $old_sorted) {
          // Links changed even though count did not.
          $message = "Scanned {$entity_type} {$eid} ($entity_label): updated file link references (now $new_count total).";
        }
      }
      if (!empty($message)) {
        $this->logger->info($message);
      }
    }
  }

}
