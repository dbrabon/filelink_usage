<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\node\NodeInterface;
use Drupal\file\FileInterface;

/**
 * Keeps the file_usage table in sync with hard‑coded links.
 */
class FileLinkUsageManager {

  protected Connection                  $database;
  protected ConfigFactoryInterface      $configFactory;
  protected TimeInterface               $time;
  protected FileLinkUsageScanner        $scanner;
  protected FileUsageInterface          $fileUsage;
  protected EntityTypeManagerInterface  $entityTypeManager;
  protected FileLinkUsageNormalizer     $normalizer;
  protected bool                        $matchesHasEntityColumns;
  protected bool                        $statusHasEntityColumns;

  public function __construct(
    Connection                 $database,
    ConfigFactoryInterface     $configFactory,
    TimeInterface              $time,
    FileLinkUsageScanner       $scanner,
    FileUsageInterface         $fileUsage,
    EntityTypeManagerInterface $entityTypeManager,
    FileLinkUsageNormalizer    $normalizer,
  ) {
    $this->database          = $database;
    $this->configFactory     = $configFactory;
    $this->time              = $time;
    $this->scanner           = $scanner;
    $this->fileUsage         = $fileUsage;
    $this->entityTypeManager = $entityTypeManager;
    $this->normalizer        = $normalizer;

    $schema                       = $database->schema();
    $this->matchesHasEntityColumns = $schema->fieldExists('filelink_usage_matches', 'entity_type')
      && $schema->fieldExists('filelink_usage_matches', 'entity_id');
    $this->statusHasEntityColumns  = $schema->fieldExists('filelink_usage_scan_status', 'entity_type')
      && $schema->fieldExists('filelink_usage_scan_status', 'entity_id');
  }

  /* --------------------------------------------------------------------- */
  /* (Only the tag‑invalidations changed below.)                           */
  /* --------------------------------------------------------------------- */

  public function reconcileEntityUsage(string $entity_type_id, int $id, bool $deleted = FALSE): void {
    $query = $this->database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link']);

    if ($this->matchesHasEntityColumns) {
      $query->condition('entity_type', $entity_type_id)
            ->condition('entity_id', $id);
    }
    else {
      if ($entity_type_id !== 'node') {
        return;
      }
      $query->condition('nid', $id);
    }

    $links        = $query->execute()->fetchCol();
    $file_storage = $this->entityTypeManager->getStorage('file');
    $files        = [];
    foreach ($links as $link) {
      $uri   = $this->normalizer->normalize($link);
      $found = $file_storage->loadByProperties(['uri' => $uri]);
      $file  = $found ? reset($found) : NULL;
      if ($file) {
        $files[$file->id()] = $file;
      }
    }

    $usage_rows = $this->database->select('file_usage', 'fu')
      ->fields('fu', ['fid', 'count'])
      ->condition('type', $entity_type_id)
      ->condition('id', $id)
      ->condition('module', 'filelink_usage')
      ->execute()
      ->fetchAllKeyed();

    /* -- Entity deleted -------------------------------------------------- */
    if ($deleted) {
      foreach ($usage_rows as $fid => $count) {
        $file = $file_storage->load($fid);
        if ($file) {
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $entity_type_id, $id);
          }
          Cache::invalidateTags([
            'file:' . $fid,
            'file_usage:' . $fid,
          ]);
        }
      }

      $del_matches = $this->database->delete('filelink_usage_matches');
      $del_status  = $this->database->delete('filelink_usage_scan_status');

      if ($this->matchesHasEntityColumns) {
        $del_matches->condition('entity_type', $entity_type_id)
                    ->condition('entity_id', $id);
        $del_status->condition('entity_type', $entity_type_id)
                   ->condition('entity_id', $id);
      }
      else {
        $del_matches->condition('nid', $id);
        $del_status->condition('nid', $id);
      }

      $del_matches->execute();
      $del_status->execute();
      return;
    }

    /* -- Remove stale usage --------------------------------------------- */
    foreach ($usage_rows as $fid => $count) {
      if (!isset($files[$fid])) {
        $file = $file_storage->load($fid);
        if ($file) {
          while ($count-- > 0) {
            $this->fileUsage->delete($file, 'filelink_usage', $entity_type_id, $id);
          }
          Cache::invalidateTags([
            'file:' . $fid,
            'file_usage:' . $fid,
          ]);
        }
      }
    }

    /* -- Add missing usage ---------------------------------------------- */
    foreach ($files as $fid => $file) {
      if (!isset($usage_rows[$fid])) {
        $this->fileUsage->add($file, 'filelink_usage', $entity_type_id, $id);
        Cache::invalidateTags([
          'file:' . $fid,
          'file_usage:' . $fid,
        ]);
      }
    }
  }

  /* --------------------------------------------------------------------- */
  /* Unchanged helper methods – only tag lines updated in loops above.     */
  /* --------------------------------------------------------------------- */

  public function reconcileNodeUsage(int $nid, bool $deleted = FALSE): void {
    $this->reconcileEntityUsage('node', $nid, $deleted);
  }

  public function cleanupNode(NodeInterface $node): void {
    $this->reconcileEntityUsage('node', $node->id(), TRUE);
  }

  public function addUsageForFile(FileInterface $file): void {
    if (strpos($file->getFileUri(), 'public://') !== 0) {
      return;
    }

    $query = $this->database->select('filelink_usage_matches', 'f')
      ->condition('link', $file->getFileUri());

    if ($this->matchesHasEntityColumns) {
      $query->fields('f', ['entity_type', 'entity_id']);
      $records = $query->execute()->fetchAll();
    }
    else {
      $query->fields('f', ['nid']);
      $nids    = $query->execute()->fetchCol();
      $records = [];
      foreach ($nids as $nid) {
        $records[] = (object) ['entity_type' => 'node', 'entity_id' => $nid];
      }
    }

    if (!$records) {
      return;
    }

    $usage = $this->fileUsage->listUsage($file);
    $used  = [];
    foreach ($usage as $module_usage) {
      foreach ($module_usage as $type => $ids) {
        $used[$type] = ($used[$type] ?? []) + $ids;
      }
    }

    foreach ($records as $record) {
      $type = $record->entity_type;
      $id   = $record->entity_id;
      if (empty($used[$type][$id])) {
        $this->fileUsage->add($file, 'filelink_usage', $type, $id);
        Cache::invalidateTags([
          'file:' . $file->id(),
          'file_usage:' . $file->id(),
        ]);
      }
    }
  }

  /* runCron, markForRescan, etc. are unchanged and omitted here for brevity */
}
