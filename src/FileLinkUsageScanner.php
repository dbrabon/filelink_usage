<?php

namespace Drupal\filelink_usage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\file\FileUsage\FileUsageInterface;
use Psr\Log\LoggerInterface;

class FileLinkUsageScanner {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected Connection $database;
  protected FileUsageInterface $fileUsage;
  protected LoggerInterface $logger;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, Connection $database, FileUsageInterface $fileUsage, LoggerInterface $logger) {
    $this->entityTypeManager = $entityTypeManager;
    $this->database = $database;
    $this->fileUsage = $fileUsage;
    $this->logger = $logger;
  }

  public function scan(): array {
    $results = [];

    $storage = $this->entityTypeManager->getStorage('node');
    $nids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->execute();

    $nodes = $storage->loadMultiple($nids);
    foreach ($nodes as $node) {
      foreach ($node->getFields() as $field) {
        if ($field->getFieldDefinition()->getType() === 'text_long' || $field->getFieldDefinition()->getType() === 'text_with_summary') {
          $text = $field->value;
          preg_match_all('/(public:\/\/[^\s"\']+|\/sites\/default\/files\/[^\s"\']+|https?:\/\/[^\/"]+\/sites\/default\/files\/[^\s"\']+)/i', $text, $matches);
          foreach ($matches[0] as $match) {
            $results[] = [
              'nid' => $node->id(),
              'link' => $match,
            ];
          }
        }
      }
    }

    $this->logger->info('Scanned @count nodes for file links.', ['@count' => count($nodes)]);
    return $results;
  }

}

