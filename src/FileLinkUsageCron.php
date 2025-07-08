<?php
declare(strict_types=1);

namespace Drupal\filelink_usage;

/**
 * Service that triggers cron-based scanning of nodes.
 */
class FileLinkUsageCron {

  /**
   * The manager handling scanning logic.
   */
  protected FileLinkUsageManager $manager;

  /**
   * Constructs the cron service.
   */
  public function __construct(FileLinkUsageManager $manager) {
    $this->manager = $manager;
  }

  /**
   * Execute the cron scan.
   */
  public function run(): void {
    $this->manager->runCron();
  }

}
