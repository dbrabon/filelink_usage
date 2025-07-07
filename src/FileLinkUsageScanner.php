<?php

namespace Drupal\filelink_usage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\node\NodeInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

class FileLinkUsageScanner {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected Connection $database;
  protected FileUsageInterface $fileUsage;
  protected LoggerInterface $logger;
  protected ConfigFactoryInterface $configFactory;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, Connection $database, FileUsageInterface $fileUsage, LoggerInterface $logger, ConfigFactoryInterface $configFactory) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->fileUsage = $fileUsage;
    $this->logger = $logger;
    $this->configFactory = $configFactory;
  }

  public function scan(?array $nids = NULL): array {
    $results = [];
    $verbose = (bool) $this->configFactory->get('filelink_usage.settings')->get('verbose_logging');

    $storage = $this->entityTypeManager->getStorage('node');
    if ($nids === NULL) {
      $nids = $storage->getQuery()
        ->accessCheck(TRUE)
        ->execute();
    }

    $nodes = $storage->loadMultiple($nids);
    foreach ($nodes as $node) {
      // Retrieve existing matches so we know which links are new.
      $links = $this->database->select('filelink_usage_matches', 'f')
        ->fields('f', ['link'])
        ->condition('nid', $node->id())
        ->execute()
        ->fetchCol();
      $existing_links = array_flip($links);

      foreach ($links as $link) {
        $uri = $link;
        if (preg_match('#https?://[^/]+(/sites/default/files/.*)#i', $uri, $m)) {
          $uri = $m[1];
        }
        if (strpos($uri, '/sites/default/files/') === 0) {
          $uri = 'public://' . substr($uri, strlen('/sites/default/files/'));
        }

        $file_storage = $this->entityTypeManager->getStorage('file');
        $files = $file_storage->loadByProperties(['uri' => $uri]);
        $file = $files ? reset($files) : NULL;
        if ($file) {
          $this->fileUsage->delete($file, 'filelink_usage', 'node', $node->id());
        }
      }

      $this->database->delete('filelink_usage_matches')
        ->condition('nid', $node->id())
        ->execute();

      foreach ($node->getFields() as $field) {
        if ($field->getFieldDefinition()->getType() === 'text_long' || $field->getFieldDefinition()->getType() === 'text_with_summary') {
          $text = $field->value;
          preg_match_all('/(public:\/\/[^\s"\']+|\/sites\/default\/files\/[^\s"\']+|https?:\/\/[^\/"]+\/sites\/default\/files\/[^\s"\']+)/i', $text, $matches);
          foreach ($matches[0] as $match) {
            $uri = $match;
            if (preg_match('#https?://[^/]+(/sites/default/files/.*)#i', $uri, $m)) {
              $uri = $m[1];
            }
            if (strpos($uri, '/sites/default/files/') === 0) {
              $uri = 'public://' . substr($uri, strlen('/sites/default/files/'));
            }

            $file_storage = $this->entityTypeManager->getStorage('file');
            $files = $file_storage->loadByProperties(['uri' => $uri]);
            $file = $files ? reset($files) : NULL;

            if ($file) {
              $usage = $this->fileUsage->listUsage($file);

              // Check if any module already recorded usage for this node.
              $usage_exists = FALSE;
              foreach ($usage as $module_name => $module_usage) {
                if (!empty($module_usage['node'][$node->id()])) {
                  // Remove entries from other modules to avoid duplicate rows.
                  if ($module_name !== 'filelink_usage') {
                    $this->fileUsage->delete($file, $module_name, 'node', $node->id());
                  }
                  $usage_exists = TRUE;
                }
              }

              if (!$usage_exists) {
                $this->fileUsage->add($file, 'filelink_usage', 'node', $node->id());
              }
            }

            $is_new_link = !isset($existing_links[$match]);

            $this->database->merge('filelink_usage_matches')
              ->keys([
                'nid' => $node->id(),
                'link' => $match,
              ])
              ->fields([
                'timestamp' => time(),
              ])
              ->execute();

            // Track this link so repeated matches in the same scan are not logged.
            $existing_links[$match] = TRUE;

            $results[] = [
              'nid' => $node->id(),
              'link' => $match,
            ];

            if ($verbose && $is_new_link) {
              $this->logger->notice('Found link @link in node @nid', [
                '@link' => $match,
                '@nid' => $node->id(),
              ]);
            }
          }
        }
      }

      // Record successful scan time.
        $this->database->merge('filelink_usage_scan_status')
          ->key('nid', $node->id())
          ->fields(['scanned' => time()])
          ->execute();
    }

    $this->logger->info('Scanned @count nodes for file links.', ['@count' => count($nodes)]);
    return $results;
  }

  /**
   * Scan a single node for file links and update usage.
   */
  public function scanNode(NodeInterface $node): array {
    return $this->scan([$node->id()]);
  }

}

