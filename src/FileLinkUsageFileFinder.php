<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\FileInterface;

/**
 * Helper service to locate managed files by normalized URIs.
 */
class FileLinkUsageFileFinder {

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileLinkUsageNormalizer $normalizer;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, FileLinkUsageNormalizer $normalizer) {
    $this->entityTypeManager = $entityTypeManager;
    $this->normalizer = $normalizer;
  }

  /**
   * Load a managed file by its normalized URI.
   */
  public function loadFileByNormalizedUri(string $uri): ?FileInterface {
    $files = $this->entityTypeManager->getStorage('file')
      ->loadByProperties(['uri' => $uri]);
    if ($files) {
      return reset($files);
    }
    $filename = basename($uri);
    $candidates = $this->entityTypeManager->getStorage('file')
      ->loadByProperties(['filename' => $filename]);
    foreach ($candidates as $candidate) {
      if ($this->normalizer->normalize($candidate->getFileUri()) === $uri) {
        return $candidate;
      }
    }
    return NULL;
  }

}
