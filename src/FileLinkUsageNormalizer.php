<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

/**
 * No‑op normalizer (placeholder for future logic).
 */
class FileLinkUsageNormalizer {

  /**
   * Normalize a raw file URL or path to a Drupal stream wrapper URI.
   */
  public function normalize(string $uri): string {
    // Strip scheme and host if present.
    $uri = preg_replace('#^https?://[^/]+#', '', $uri);
    // Remove query strings or fragments.
    $uri = preg_replace('#[?#].*$#', '', $uri);
    // Collapse duplicate slashes.
    $uri = preg_replace('#/+#', '/', $uri);
    // Decode percent encoded characters.
    $uri = rawurldecode($uri);

    if (str_contains($uri, '/sites/default/files/')) {
      $uri = 'public://' . ltrim(explode('/sites/default/files/', $uri, 2)[1], '/');
    }
    elseif (str_contains($uri, '/system/files/')) {
      $uri = 'private://' . ltrim(explode('/system/files/', $uri, 2)[1], '/');
    }

    return $uri;
  }

}
