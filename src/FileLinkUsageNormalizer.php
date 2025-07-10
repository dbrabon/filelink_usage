<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

/**
 * No‑op normalizer (placeholder for future logic).
 */
class FileLinkUsageNormalizer {

  /**
   * Return the URI unchanged for now.
   */
  public function normalize(string $uri): string {
    return $uri;
  }

}
