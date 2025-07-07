<?php

namespace Drupal\filelink_usage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\file\FileUsage\FileUsageInterface;
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
      // Remove old usage records and matches so we can store a fresh set.
      $links = $this->database->select('filelink_usage_matches', 'f')
        ->fields('f', ['link'])
        ->condition('nid', $node->id())
        ->execute()
        ->fetchCol();

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
              if (empty($usage['filelink_usage']['node'][$node->id()])) {
                $this->fileUsage->add($file, 'filelink_usage', 'node', $node->id());
              }
            }

            $this->database->merge('filelink_usage_matches')
              ->keys([
                'nid' => $node->id(),
                'link' => $match,
              ])
              ->fields([
                'timestamp' => time(),
              ])
              ->execute();

            $results[] = [
              'nid' => $node->id(),
              'link' => $match,
            ];

            if ($verbose) {
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

}

