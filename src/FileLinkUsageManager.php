<?php

namespace Drupal\filelink_usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\Cache;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

class FileLinkUsageManager {

  protected Connection $database;
  protected ConfigFactoryInterface $configFactory;
  protected TimeInterface $time;
  protected FileLinkUsageScanner $scanner;
  protected FileUsageInterface $fileUsage;
  protected EntityTypeManagerInterface $entityTypeManager;

  public function __construct(Connection $database, ConfigFactoryInterface $configFactory, TimeInterface $time, FileLinkUsageScanner $scanner, FileUsageInterface $fileUsage, EntityTypeManagerInterface $entityTypeManager) {
    $this->database = $database;
    $this->configFactory = $configFactory;
    $this->time = $time;
    $this->scanner = $scanner;
    $this->fileUsage = $fileUsage;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Execute cron scanning based on configured frequency.
   */
  public function runCron(): void {
    $config = $this->configFactory->getEditable('filelink_usage.settings');
    $frequency = $config->get('scan_frequency');
    $last_scan = (int) $config->get('last_scan');
    $intervals = [
      'every' => 0,
      'hourly' => 3600,
      'daily' => 86400,
      'weekly' => 604800,
    ];
    $interval = $intervals[$frequency] ?? 86400;
    $now = $this->time->getRequestTime();

    if ($interval && $last_scan + $interval > $now) {
      return;
    }

    $threshold = $interval ? $now - $interval : $now;
    $query = $this->database->select('node_field_data', 'n');
    $query->leftJoin('filelink_usage_scan_status', 's', 'n.nid = s.nid');
    $query->fields('n', ['nid']);
    $or = $query->orConditionGroup()
      ->isNull('s.scanned')
      ->condition('s.scanned', $threshold, '<');
    $nids = $query->condition($or)->execute()->fetchCol();

    if ($nids) {
      $this->scanner->scan($nids);
    }

    $config->set('last_scan', $now)->save();
  }

  /**
   * Mark a node for rescan by clearing its status.
   */
  public function markForRescan(NodeInterface $node): void {
    $this->database->delete('filelink_usage_scan_status')
      ->condition('nid', $node->id())
      ->execute();
  }

  /**
   * Cleanup file usage when a node is deleted.
   */
  public function cleanupNode(NodeInterface $node): void {
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
        Cache::invalidateTags(['file:' . $file->id()]);
      }
    }

    $this->database->delete('filelink_usage_matches')
      ->condition('nid', $node->id())
      ->execute();

    $this->database->delete('filelink_usage_scan_status')
      ->condition('nid', $node->id())
      ->execute();
  }

}
