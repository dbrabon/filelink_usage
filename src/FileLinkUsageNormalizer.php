<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

use Drupal\Component\Utility\Html;

class FileLinkUsageNormalizer {

  public function normalize(string $link): string {
    // Decode HTML entities and trim common surrounding characters.
    $link = Html::decodeEntities($link);
    $link = trim($link, " \t\n\r\0\x0B\"'");

    // Strip hostnames from absolute URLs.
    if (preg_match('#^https?://[^/]+(/.*)$#i', $link, $m)) {
      $link = $m[1];
    }

    // Collapse repeated slashes.
    $link = preg_replace('#/{2,}#', '/', $link);

    // Remove query strings and fragments.
    $link = preg_replace('/[\?#].*$/', '', $link);
    $link = rtrim($link, '/');

    // Convert sites/default/files paths to public scheme.
    if (strpos($link, '/sites/default/files/') === 0) {
      $link = 'public://' . substr($link, strlen('/sites/default/files/'));
    }

    return $link;
  }

}
