<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Component\Utility\Html;

/**
 * Converts any form of link to a canonical `public://…` URI.
 */
class FileLinkUsageNormalizer {

  /**
   * Normalize a link so scanners & lookups are consistent.
   *
   * @param string $link
   *   Raw link captured from HTML.
   *
   * @return string
   *   Canonical URI (e.g. `public://abc.pdf`).
   */
  public function normalize(string $link): string {
    // Decode entities and strip quotes / whitespace.
    $link = Html::decodeEntities($link);
    $link = trim($link, " \t\n\r\0\x0B\"'");

    // If it is already public://, leave it untouched.
    if (strpos($link, 'public://') === 0) {
      return $link;
    }

    // Strip host portion from absolute URLs.
    if (preg_match('#^https?://[^/]+(/.*)$#i', $link, $m)) {
      $link = $m[1];
    }

    // Collapse duplicate slashes.
    $link = preg_replace('#/{2,}#', '/', $link);

    // Remove query strings / fragments.
    $link = preg_replace('/[\?#].*$/', '', $link);
    $link = rtrim($link, '/');

    // Turn /sites/default/files/... into public://...
    if (strpos($link, '/sites/default/files/') === 0) {
      $link = 'public://' . substr($link, strlen('/sites/default/files/'));
    }

    return $link;
  }

}
