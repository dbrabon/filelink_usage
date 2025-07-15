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
    // Remove query strings and fragments.
    $uri = preg_replace('/[#?].*/', '', $uri);

    // Collapse duplicate slashes while ignoring the scheme separator.
    $uri = preg_replace('#(?<!:)/{2,}#', '/', $uri);

    // Remove trailing /index or /index.html type suffixes.
    $uri = preg_replace('#/index(?:\.html?)?$#i', '', $uri);

    $public = '/sites/default/files/';
    $private = '/system/files/';

    if (str_contains($uri, $public)) {
      $path = preg_replace('#^https?://[^/]+#', '', $uri);
      $path = explode($public, $path, 2)[1] ?? '';
      $path = rawurldecode($path);
      return 'public://' . ltrim($path, '/');
    }

    if (str_contains($uri, $private)) {
      $path = preg_replace('#^https?://[^/]+#', '', $uri);
      $path = explode($private, $path, 2)[1] ?? '';
      $path = rawurldecode($path);
      return 'private://' . ltrim($path, '/');
    }

    if (str_starts_with($uri, 'public://')) {
      $path = rawurldecode(substr($uri, strlen('public://')));
      return 'public://' . ltrim($path, '/');
    }

    if (str_starts_with($uri, 'private://')) {
      $path = rawurldecode(substr($uri, strlen('private://')));
      return 'private://' . ltrim($path, '/');
    }

    return $uri;
  }

}
