<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Drupal\filelink_usage\FileLinkUsageNormalizer;

/**
 * Scans content entities for file links and records usage.
 */
class FileLinkUsageScanner {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected Connection $database;
  protected FileUsageInterface $fileUsage;
  protected LoggerInterface $logger;
  protected ConfigFactoryInterface $configFactory;
  protected FileLinkUsageNormalizer $normalizer;
  protected TimeInterface $time;

  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    Connection $database,
    FileUsageInterface $fileUsage,
    LoggerInterface $logger,
    ConfigFactoryInterface $configFactory,
    FileLinkUsageNormalizer $normalizer,
    TimeInterface $time
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database          = $database;
    $this->fileUsage         = $fileUsage;
    $this->logger            = $logger;
    $this->configFactory     = $configFactory;
    $this->normalizer        = $normalizer;
    $this->time              = $time;
  }

  /**
   * Scan one or more content entities for file links.
   *
   * @param int[]|null $ids
   *   Entity IDs to scan. If NULL, every accessible entity is scanned.
   * @param string $entity_type_id
   *   The entity type ID to scan.
   *
   * @return array<int, array{entity_id:int,link:string}>
   *   A list of discovered `(entity_id, link)` pairs.
   */
  public function scan(?array $ids = NULL, string $entity_type_id = 'node'): array {
    $results = [];
    $verbose = (bool) $this->configFactory
      ->get('filelink_usage.settings')
      ->get('verbose_logging');

    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    if ($ids === NULL) {
      $ids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->execute();
    }

    $entities = $storage->loadMultiple($ids);
    $count = 0;

    foreach ($entities as $entity) {
      // ------------------------------------------------------------------
      // Remove prior matches so the table is repopulated cleanly.
      // ------------------------------------------------------------------
      $links = $this->database->select('filelink_usage_matches', 'f')
        ->fields('f', ['link'])
        ->condition('entity_type', $entity_type_id)
        ->condition('entity_id', $entity->id())
        ->execute()
        ->fetchCol();

      $existing_links = [];

      foreach ($links as $link) {
        $uri = $this->normalizer->normalize($link);
        $existing_links[$uri] = TRUE;

        $file_storage = $this->entityTypeManager->getStorage('file');
        $files        = $file_storage->loadByProperties(['uri' => $uri]);
        $file         = $files ? reset($files) : NULL;

        if ($file) {
          $this->fileUsage->delete($file, 'filelink_usage', $entity_type_id, $entity->id());
          Cache::invalidateTags(['file:' . $file->id()]);
        }
      }

      $this->database->delete('filelink_usage_matches')
        ->condition('entity_type', $entity_type_id)
        ->condition('entity_id', $entity->id())
        ->execute();

      // ------------------------------------------------------------------
      // Parse text fields for potential file links.
      // ------------------------------------------------------------------
      foreach ($entity->getFields() as $field) {
        $type = $field->getFieldDefinition()->getType();

        // Only inspect long‑text fields.
        if ($type !== 'text_long' && $type !== 'text_with_summary') {
          continue;
        }

        $texts = [$field->value];
        if ($type === 'text_with_summary') {
          $texts[] = $field->summary;
        }

        foreach ($texts as $text) {
          if ($text === NULL || $text === '') {
            continue;
          }

          preg_match_all(
            '/(public:\/\/[^"\']+|\/sites\/default\/files\/[^"\']+|https?:\/\/[^\/"]+\/sites\/default\/files\/[^"\']+)/i',
            $text,
            $matches
          );

          foreach ($matches[0] as $match) {
            $uri = $this->normalizer->normalize($match);

            $file_storage = $this->entityTypeManager->getStorage('file');
            $files        = $file_storage->loadByProperties(['uri' => $uri]);
            $file         = $files ? reset($files) : NULL;

            if ($file) {
              $usage = $this->fileUsage->listUsage($file);

              // If another module already marked usage for this node, remove it.
              $usage_exists = FALSE;
              foreach ($usage as $module_name => $module_usage) {
                if (!empty($module_usage[$entity_type_id][$entity->id()])) {
                  if ($module_name !== 'filelink_usage') {
                    $this->fileUsage->delete($file, $module_name, $entity_type_id, $entity->id());
                  }
                  $usage_exists = TRUE;
                }
              }

              if (!$usage_exists) {
                $this->fileUsage->add($file, 'filelink_usage', $entity_type_id, $entity->id());
              }

              Cache::invalidateTags(['file:' . $file->id()]);
            }

            $is_new_link = !isset($existing_links[$uri]);

            // Upsert the match row.
            $this->database->merge('filelink_usage_matches')
              ->keys([
                'entity_type' => $entity_type_id,
                'entity_id'   => $entity->id(),
                'link'        => $uri,
              ])
              ->fields(['timestamp' => $this->time->getRequestTime()])
              ->execute();

            $existing_links[$uri] = TRUE;
            $results[] = ['entity_id' => $entity->id(), 'link' => $uri];

            if ($verbose && $is_new_link) {
              $this->logger->notice(
                'Found link @link in @type @id',
                ['@link' => $uri, '@type' => $entity_type_id, '@id' => $entity->id()]
              );
            }
          }
        }
      }

      // ------------------------------------------------------------------
      // Record successful scan time.
      // ------------------------------------------------------------------
      $this->database->merge('filelink_usage_scan_status')
        ->keys([
          'entity_type' => $entity_type_id,
          'entity_id' => $entity->id(),
        ])
        ->fields(['scanned' => $this->time->getRequestTime()])
        ->execute();

      $count++;
      if ($verbose && $count % 100 === 0) {
        $this->logger->info(
          'Scanned @count entities for file links so far.',
          ['@count' => $count]
        );
      }
    }

    // --------------------------------------------------------------------
    // Summary logging.
    // --------------------------------------------------------------------
    $ids_list = implode(', ', array_keys($entities));
    if (count($entities) === 1) {
      $this->logger->info('Scanned @type @ids for file links.', ['@ids' => $ids_list, '@type' => $entity_type_id]);
    }
    else {
      $this->logger->info(
        'Scanned @count @type entities for file links: @ids.',
        ['@count' => count($entities), '@ids' => $ids_list, '@type' => $entity_type_id]
      );
    }

    return $results;
  }

  /**
   * Convenience wrapper to scan a single node.
   */
  public function scanNode(NodeInterface $node): array {
    return $this->scan([$node->id()], 'node');
  }

  /**
   * Convenience wrapper to scan a single content entity.
   */
  public function scanEntity(ContentEntityInterface $entity): array {
    return $this->scan([$entity->id()], $entity->getEntityTypeId());
  }

}
