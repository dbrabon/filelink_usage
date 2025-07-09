<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Drupal\filelink_usage\FileLinkUsageNormalizer;

/**
 * Scans nodes for file links and records usage.
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
   * Scan one or more nodes for file links.
   *
   * @param int[]|null $nids
   *   Node IDs to scan. If NULL, every accessible node is scanned.
   *
   * @return array<int, array{nid:int,link:string}>
   *   A list of discovered `(nid, link)` pairs.
   */
  public function scan(?array $nids = NULL): array {
    $results = [];
    $verbose = (bool) $this->configFactory
      ->get('filelink_usage.settings')
      ->get('verbose_logging');

    $storage = $this->entityTypeManager->getStorage('node');
    if ($nids === NULL) {
      $nids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->execute();
    }

    $nodes = $storage->loadMultiple($nids);
    $count = 0;

    foreach ($nodes as $node) {
      // ------------------------------------------------------------------
      // Remove prior matches so the table is repopulated cleanly.
      // ------------------------------------------------------------------
      $links = $this->database->select('filelink_usage_matches', 'f')
        ->fields('f', ['link'])
        ->condition('nid', $node->id())
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
          $this->fileUsage->delete($file, 'filelink_usage', 'node', $node->id());
          Cache::invalidateTags(['file:' . $file->id()]);
        }
      }

      $this->database->delete('filelink_usage_matches')
        ->condition('nid', $node->id())
        ->execute();

      // ------------------------------------------------------------------
      // Parse text fields for potential file links.
      // ------------------------------------------------------------------
      foreach ($node->getFields() as $field) {
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
                if (!empty($module_usage['node'][$node->id()])) {
                  if ($module_name !== 'filelink_usage') {
                    $this->fileUsage->delete($file, $module_name, 'node', $node->id());
                  }
                  $usage_exists = TRUE;
                }
              }

              if (!$usage_exists) {
                $this->fileUsage->add($file, 'filelink_usage', 'node', $node->id());
              }

              Cache::invalidateTags(['file:' . $file->id()]);
            }

            $is_new_link = !isset($existing_links[$uri]);

            // Upsert the match row.
            $this->database->merge('filelink_usage_matches')
              ->keys([
                'nid'  => $node->id(),
                'link' => $uri,
              ])
              ->fields(['timestamp' => $this->time->getRequestTime()])
              ->execute();

            $existing_links[$uri] = TRUE;
            $results[] = ['nid' => $node->id(), 'link' => $uri];

            if ($verbose && $is_new_link) {
              $this->logger->notice(
                'Found link @link in node @nid',
                ['@link' => $uri, '@nid' => $node->id()]
              );
            }
          }
        }
      }

      // ------------------------------------------------------------------
      // Record successful scan time.
      // ------------------------------------------------------------------
      $this->database->merge('filelink_usage_scan_status')
        ->key('nid', $node->id())
        ->fields(['scanned' => $this->time->getRequestTime()])
        ->execute();

      $count++;
      if ($verbose && $count % 100 === 0) {
        $this->logger->info(
          'Scanned @count nodes for file links so far.',
          ['@count' => $count]
        );
      }
    }

    // --------------------------------------------------------------------
    // Summary logging.
    // --------------------------------------------------------------------
    $nids_list = implode(', ', array_keys($nodes));
    if (count($nodes) === 1) {
      $this->logger->info('Scanned node @nids for file links.', ['@nids' => $nids_list]);
    }
    else {
      $this->logger->info(
        'Scanned @count nodes for file links: @nids.',
        ['@count' => count($nodes), '@nids' => $nids_list]
      );
    }

    return $results;
  }

  /**
   * Convenience wrapper to scan a single node.
   */
  public function scanNode(NodeInterface $node): array {
    return $this->scan([$node->id()]);
  }

}
