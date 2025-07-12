<?php
declare(strict_types=1);

namespace Drupal\filelink_usage\Commands;

use Drush\Commands\DrushCommands;
use Drupal\filelink_usage\FileLinkUsageManager;

class FileLinkUsageCommands extends DrushCommands {

  protected FileLinkUsageManager $manager;

  public function __construct(FileLinkUsageManager $manager) {
    $this->manager = $manager;
  }

  /**
   * Run the Filelink Usage scanning routine.
   *
   * @command filelink_usage:scan
   * @aliases flus
   * @usage drush filelink_usage:scan
   *   Runs the same scanning process that cron triggers, scanning any entities
   *   marked for a rescan.
   */
  public function scan(): void {
    $this->manager->runCron();
    $this->logger()->success('File link scan complete.');
  }

}
