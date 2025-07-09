<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Cache\Cache;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\file\FileInterface;
use Drupal\filelink_usage\FileLinkUsageNormalizer;

class FileLinkUsageManager {

  protected Connection $database;
  protected ConfigFactoryInterface $configFactory;
  protected TimeInterface $time;
  protected FileLinkUsageScanner $scanner;
  protected FileUsageInterface $fileUsage;
  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileLinkUsageNormalizer $normalizer;

  public function __construct(Connection $database, ConfigFactoryInterface $configFactory, TimeInterface $time, FileLinkUsageScanner $scanner, FileUsageInterface $fileUsage, EntityTypeManagerInterface $entityTypeManager, FileLinkUsageNormalizer $normalizer) {
    $this->database = $database;
    $this->configFactory = $configFactory;
    $this->time = $time;
    $this->scanner = $scanner;
    $this->fileUsage = $fileUsage;
    $this->entityTypeManager = $entityTypeManager;
    $this->normalizer = $normalizer;
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
      'monthly' => 2592000,
      'yearly' => 31536000,
    ];
    $interval = $intervals[$frequency] ?? 31536000;
    $now = $this->time->getRequestTime();

    $match_count = (int) $this->database->select('filelink_usage_matches')
      ->countQuery()
      ->execute()
      ->fetchField();
    $force_rescan = $match_count === 0;

    if (!$force_rescan && $interval && $last_scan + $interval > $now) {
      return;
    }

    if (!$force_rescan) {
      $threshold = $interval ? $now - $interval : $now;
      $query = $this->database->select('node_field_data', 'n');
      $query->leftJoin('filelink_usage_scan_status', 's', 'n.nid = s.nid');
      $query->fields('n', ['nid']);
      $or = $query->orConditionGroup()
        ->isNull('s.scanned')
        ->condition('s.scanned', $threshold, '<');
      $nids = $query->condition($or)->execute()->fetchCol();
    }
    else {
      $nids = $this->database->select('node_field_data', 'n')
        ->fields('n', ['nid'])
        ->execute()
        ->fetchCol();
    }

    if ($nids) {
      $this->scanner->scan($nids);
      foreach ($nids as $nid) {
        $this->reconcileNodeUsage((int) $nid);
      }
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
   * Clear scan status for all nodes.
   */
  public function markAllForRescan(): void {
    $this->database->truncate('filelink_usage_scan_status')->execute();
  }

  /**
   * Reconcile file usage records for a node.
   *
   * @param int $nid
   *   The node ID.
   * @param bool $deleted
   *   TRUE if the node was deleted and all usage should be removed.
   */
  public function reconcileNodeUsage(int $nid, bool $deleted = FALSE): void {
    // Load links recorded for this node.
    $links = $this->database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('nid', $nid)
      ->execute()
      ->fetchCol();

    $file_storage = $this->entityTypeManager->getStorage('file');
    $files = [];
    foreach ($links as $link) {
      $uri = $this->normalizer->normalize($link);
      $loaded = $file_storage->loadByProperties(['uri' => $uri]);
      $file = $loaded ? reset($loaded) : NULL;
      if ($file) {
        $files[$file->id()] = $file;
      }
    }

    // Current usage rows for the node.
    $usage_rows = $this->database->select('file_usage', 'fu')
      ->fields('fu', ['fid', 'count'])
      ->condition('type', 'node')
      ->condition('id', $nid)
      ->condition('module', 'filelink_usage')
      ->execute()
      ->fetchAllKeyed();

    // If the node was deleted remove all usage and cleanup tables.
    if ($deleted) {
      foreach ($usage_rows as $fid => $count) {
        $file = $file_storage->load($fid);
        if ($file) {
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', 'node', $nid);
          }
          Cache::invalidateTags(['file:' . $fid]);
        }
      }

      $this->database->delete('filelink_usage_matches')
        ->condition('nid', $nid)
        ->execute();

      $this->database->delete('filelink_usage_scan_status')
        ->condition('nid', $nid)
        ->execute();

      return;
    }

    // Remove usage that no longer has a matching link.
    foreach ($usage_rows as $fid => $count) {
      if (!isset($files[$fid])) {
        $file = $file_storage->load($fid);
        if ($file) {
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', 'node', $nid);
          }
          Cache::invalidateTags(['file:' . $fid]);
        }
      }
    }

    // Add usage for new links not yet recorded.
    foreach ($files as $fid => $file) {
      if (!isset($usage_rows[$fid])) {
        $this->fileUsage->add($file, 'filelink_usage', 'node', $nid);
        Cache::invalidateTags(['file:' . $fid]);
      }
    }
  }

  /**
   * Cleanup file usage when a node is deleted.
   */
  public function cleanupNode(NodeInterface $node): void {
    $this->reconcileNodeUsage($node->id(), TRUE);
  }

  /**
   * Add file usage entries when a file entity is created.
   */
  public function addUsageForFile(FileInterface $file): void {
    $uri = $file->getFileUri();
    if (strpos($uri, 'public://') !== 0) {
      return;
    }

    $query = $this->database->select('filelink_usage_matches', 'f')
      ->fields('f', ['nid'])
      ->condition('link', $uri);
    $nids = $query->execute()->fetchCol();

    if (!$nids) {
      return;
    }

    $usage = $this->fileUsage->listUsage($file);
    $used_nids = [];
    foreach ($usage as $module_usage) {
      if (!empty($module_usage['node'])) {
        $used_nids += $module_usage['node'];
      }
    }

    foreach ($nids as $nid) {
      if (!isset($used_nids[$nid])) {
        $this->fileUsage->add($file, 'filelink_usage', 'node', $nid);
        Cache::invalidateTags(['file:' . $file->id()]);
      }
    }
  }

}
