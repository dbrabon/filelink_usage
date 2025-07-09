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
 * Scans content entities for hard-coded file links and records matches.
 */
class FileLinkUsageScanner {

  /* -----------------------------------------------------------------------
   * Dependencies
   * --------------------------------------------------------------------- */

  protected Connection $database;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileUsageInterface $fileUsage;
  protected ConfigFactoryInterface $configFactory;
  protected LoggerInterface $logger;
  protected TimeInterface $time;
  protected FileLinkUsageNormalizer $normalizer;

  /* -----------------------------------------------------------------------
   * Schema detection
   * --------------------------------------------------------------------- */

  protected bool $matchesHasEntityColumns;
  protected bool $statusHasEntityColumns;

  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    FileUsageInterface $file_usage,
    ConfigFactoryInterface $config_factory,
    LoggerInterface $logger,
    TimeInterface $time,
    FileLinkUsageNormalizer $normalizer
  ) {
    $schema = $database->schema();
    $this->matchesHasEntityColumns = $schema->fieldExists('filelink_usage_matches', 'entity_type')
      && $schema->fieldExists('filelink_usage_matches', 'entity_id');
    $this->statusHasEntityColumns  = $schema->fieldExists('filelink_usage_scan_status', 'entity_type')
      && $schema->fieldExists('filelink_usage_scan_status', 'entity_id');

    $this->database           = $database;
    $this->entityTypeManager  = $entity_type_manager;
    $this->fileUsage          = $file_usage;
    $this->configFactory      = $config_factory;
    $this->logger             = $logger;
    $this->time               = $time;
    $this->normalizer         = $normalizer;
  }

  /**
   * Scan content for file links.
   *
   * @param int[]|NULL $ids
   *   (optional) Specific entity IDs to scan. If NULL, all entities of the
   *   given type will be scanned.
   * @param string $entity_type_id
   *   (optional) Entity type to scan; defaults to 'node'.
   *
   * @return array
   *   Array of results with 'entity_id' and 'link' for each match found.
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
    $count    = 0;

    foreach ($entities as $entity) {
      /* --------------------------------------------------------------------
       * 1.  Remove prior matches so the table is repopulated cleanly
       * ------------------------------------------------------------------ */
      $query = $this->database->select('filelink_usage_matches', 'f')
        ->fields('f', ['link']);

      if ($this->matchesHasEntityColumns) {
        $query->condition('entity_type', $entity_type_id)
              ->condition('entity_id', $entity->id());
      }
      else {
        // Legacy schema with column "nid".
        $query->condition('nid', $entity->id());
      }

      $links          = $query->execute()->fetchCol();
      $existing_links = [];

      foreach ($links as $link) {
        $uri                    = $this->normalizer->normalize($link);
        $existing_links[$uri]   = TRUE;
      }

      /* Delete matches for this entity. */
      $delete = $this->database->delete('filelink_usage_matches');
      if ($this->matchesHasEntityColumns) {
        $delete->condition('entity_type', $entity_type_id)
               ->condition('entity_id', $entity->id());
      }
      else {
        $delete->condition('nid', $entity->id());
      }
      $delete->execute();

      /* --------------------------------------------------------------------
       * 2.  Inspect long‑text fields for potential file links
       * ------------------------------------------------------------------ */
      $processed_files = [];
      foreach ($entity->getFields() as $field) {
        $type = $field->getFieldDefinition()->getType();
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

          // Capture absolute, relative, and stream‑wrapper file URLs.
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
               if (!isset($processed_files[$file->id()])) {
                 $usage = $this->fileUsage->listUsage($file);
                 $found_filelink_usage = FALSE;
                 foreach ($usage as $module_name => $module_usage) {
                   if (!empty($module_usage[$entity_type_id][$entity->id()])) {
                     $count = $module_usage[$entity_type_id][$entity->id()];
                     if ($module_name === 'filelink_usage') {
                       $found_filelink_usage = TRUE;
                       if ($count > 1) {
                         while ($count-- > 1) {
                           $this->fileUsage->delete($file, 'filelink_usage', $entity_type_id, $entity->id());
                         }
                       }
                     }
                     else {
                       // Remove usage entries from other modules for this entity.
                       while ($count-- > 0) {
                         $this->fileUsage->delete($file, $module_name, $entity_type_id, $entity->id());
                       }
                     }
                   }
                 }
                 if (!$found_filelink_usage) {
                   $this->fileUsage->add($file, 'filelink_usage', $entity_type_id, $entity->id());
                 }
                 Cache::invalidateTags(['file:' . $file->id()]);
                 $processed_files[$file->id()] = TRUE;
               }
            }

            $is_new_link = !isset($existing_links[$uri]);

            /* Upsert the match row (schema‑aware). */
            $merge = $this->database->merge('filelink_usage_matches');
            if ($this->matchesHasEntityColumns) {
              $merge->keys([
                'entity_type' => $entity_type_id,
                'entity_id'   => $entity->id(),
                'link'        => $uri,
              ]);
            }
            else {
              $merge->keys([
                'nid'  => $entity->id(),
                'link' => $uri,
              ]);
            }
            $merge->fields(['timestamp' => $this->time->getRequestTime()])
                  ->execute();

            $existing_links[$uri] = TRUE;
            $results[]            = ['entity_id' => $entity->id(), 'link' => $uri];

            if ($verbose && $is_new_link) {
              $this->logger->notice(
                'Found link @link in @type @id',
                ['@link' => $uri, '@type' => $entity_type_id, '@id' => $entity->id()]
              );
            }
          }
        }
      }

      /* --------------------------------------------------------------------
       * 3.  Record successful scan time.
       * ------------------------------------------------------------------ */
      $merge = $this->database->merge('filelink_usage_scan_status');
      if ($this->statusHasEntityColumns) {
        $merge->keys([
          'entity_type' => $entity_type_id,
          'entity_id'   => $entity->id(),
        ]);
      }
      else {
        // Legacy schema with column "nid".
        $merge->key('nid', $entity->id());
      }
      $merge->fields(['scanned' => $this->time->getRequestTime()])
            ->execute();

      /* --------------------------------------------------------------------
       * 4.  Progress logging.
       * ------------------------------------------------------------------ */
      $count++;
      if ($verbose && $count % 100 === 0) {
        $this->logger->info(
          'Scanned @count entities for file links so far.',
          ['@count' => $count]
        );
      }
    }

    /* ----------------------------------------------------------------------
     * Summary logging
     * -------------------------------------------------------------------- */
    $ids_list = implode(', ', array_keys($entities));
    if (count($entities) === 1) {
      $this->logger->info(
        'Scanned @type @ids for file links.',
        ['@ids' => $ids_list, '@type' => $entity_type_id]
      );
    }
    else {
      $this->logger->info(
        'Scanned @count @type entities for file links: @ids.',
        [
          '@count' => count($entities),
          '@ids'   => $ids_list,
          '@type'  => $entity_type_id,
        ]
      );
    }

    return $results;
  }

}
