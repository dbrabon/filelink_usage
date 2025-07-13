<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

/**
 * Normalizes file URIs for consistent comparison.
 */
class FileLinkUsageNormalizer {

  /**
   * Normalize a file URI by decoding and trimming extra parts.
   */
  public function normalize(string $uri): string {
    [$scheme, $path] = explode('://', $uri, 2);
    // Remove query strings and fragments.
    $path = preg_replace('/[?#].*/', '', $path);
    // Collapse duplicate slashes.
    $path = preg_replace('#/{2,}#', '/', $path);
    // Decode percent-encoded characters.
    $path = rawurldecode($path);
    return $scheme . '://' . $path;
  }

}
