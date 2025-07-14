<?php

declare(strict_types=1);

namespace Drupal\filelink_usage;

/**
 * Normalizes file links found in HTML.
 */
class FileLinkUsageNormalizer {

  /**
   * Normalize a file link to a Drupal stream URI.
   *
   * @param string $uri
   *   The raw link extracted from HTML.
   *
   * @return string
   *   The normalized URI (public:// or private:// where possible).
   */
  public function normalize(string $uri): string {
    // Extract just the path portion and decode percent-encoding.
    $path = parse_url($uri, PHP_URL_PATH) ?? $uri;
    $path = rawurldecode($path);

    // Remove trailing /index or /index.html type suffixes.
    $path = preg_replace('#/index(?:\.html?)?$#i', '', $path);

    // Collapse duplicate slashes.
    $path = preg_replace('#/+#', '/', $path);

    $public = '/sites/default/files/';
    $private = '/system/files/';

    if (str_starts_with($path, $public)) {
      $path = substr($path, strlen($public));
      return 'public://' . ltrim($path, '/');
    }

    if (str_starts_with($path, $private)) {
      $path = substr($path, strlen($private));
      return 'private://' . ltrim($path, '/');
    }

    return $path;
  }

}
