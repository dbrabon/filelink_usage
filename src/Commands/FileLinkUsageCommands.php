<?php

namespace Drupal\filelink_usage\Commands;

use Drush\Commands\DrushCommands;
use Drupal\filelink_usage\FileLinkUsageScanner;

class FileLinkUsageCommands extends DrushCommands {

  protected FileLinkUsageScanner $scanner;

  public function __construct(FileLinkUsageScanner $scanner) {
    $this->scanner = $scanner;
  }

  /**
   * Scan nodes for file links in text fields.
   *
   * @command filelink_usage:scan
   * @aliases flus
   * @usage filelink_usage:scan
   */
  public function scan(): void {
    $results = $this->scanner->scan();
    foreach ($results as $item) {
      $this->output()->writeln("Node {$item['nid']} → {$item['link']}");
    }
    $this->logger()->success(count($results) . ' links found.');
  }

}
