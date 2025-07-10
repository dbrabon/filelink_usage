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
 * Scans content entities for hard‑linked files and records their usage.
 *
 * This scanner is intentionally conservative: it looks only at files that are
 * referenced via absolute URLs in rendered HTML (e.g. links or image sources
 * pointing into /sites/default/files or /system/files).  Media–field
 * references, file entities, etc. are all handled by core’s regular file usage
 * tracking; this tool focuses on “naked” links that would otherwise be missed.
 */
class FileLinkUsageScanner {

  /**
   * Regular expression fragment that matches file paths inside src/href.
   */
  public const FILE_LINK_REGEX =
    '/(?:src|href)="([^"]*\\/(?:sites\\/default\\/files|system\\/files)\\/[^"]+)"/i';

  /**
   * Constructs the scanner service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected RendererInterface          $renderer,
    protected Connection                 $database,
    protected FileUsageInterface         $fileUsage,
    protected ConfigFactoryInterface     $configFactory,
    protected TimeInterface              $time,
    protected LoggerInterface            $logger,
  ) {}

  /**
   * Scan a list of entity IDs for hard‑linked file usage.
   *
   * @param array $entity_ids
   *   IDs keyed by entity type.
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
   * Scan a single entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   */
  protected function scanEntity(EntityInterface $entity): void {
    // 1. Bail early if not a content entity that can contain renderable markup.
    $entity_type = $entity->getEntityType()->id();
    if (!in_array($entity_type, ['node', 'block_content'])) {
      return;
    }

    /* 2. Render the entity and find all file URLs in its HTML */
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

    $found_fids = [];
    $found_uris = [];
    foreach ($file_urls as $url) {
      $uri = NULL;
      if (strpos($url, '/sites/default/files/') !== FALSE) {
        // Convert public file URL to public:// URI.
        $uri = 'public://' . str_replace('/sites/default/files/', '', $url);
      }
      elseif (strpos($url, '/system/files/') !== FALSE) {
        // Convert private file URL to private:// URI.
        $uri = 'private://' . str_replace('/system/files/', '', $url);
      }

      if ($uri === NULL) {
        continue;
      }

      // Try to load the file entity for this URI.
      /** @var \Drupal\Core\Entity\Query\QueryInterface $query */
      $query = $this->entityTypeManager->getStorage('file')->getQuery()
        ->condition('uri', $uri)
        ->accessCheck(FALSE)
        ->range(0, 1);
      $fids = $query->execute();

      if ($fids) {
        $fid = reset($fids);
        $found_fids[] = $fid;
        $found_uris[] = $uri;
      }
    }

    /* 3. Record file usages */
    foreach ($found_fids as $index => $fid) {
      /** @var \Drupal\file\FileInterface $file */
      $file = $this->entityTypeManager->getStorage('file')->load($fid);
      if (!$file) {
        continue;
      }

      // Register as a usage for the entity under a special module (+type) name.
      $this->fileUsage->add($file, 'filelink_usage', $entity->getEntityTypeId(), $entity->id(), 1);

      // Store which specific link/URI caused the usage trace so we can report.
      $this->database->merge('filelink_usage_matches')
        ->key([
          'entity_type' => $entity->getEntityTypeId(),
          'entity_id'   => $entity->id(),
          'link'        => $file->getFileUri(),
        ])
        ->fields(['timestamp' => $this->time->getRequestTime()])
        ->execute();
      // Invalidate cache for this file to reflect new usage.
      Cache::invalidateTags(['file:' . $fid]);
    }
  }

}

