<?php

declare(strict_types=1);

namespace Drupal\Tests\filelink_usage\Unit;

require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/FileLinkUsageManager.php';
require_once __DIR__ . '/../../../src/Commands/FileLinkUsageCommands.php';

use Drupal\filelink_usage\Commands\FileLinkUsageCommands;
use Drupal\filelink_usage\FileLinkUsageManager;
use PHPUnit\Framework\TestCase;
use Drush\Log\DrushLoggerManager;

class FileLinkUsageCommandsTest extends TestCase {
    public function testScanInvokesRunCron(): void {
        $manager = $this->createMock(FileLinkUsageManager::class);
        $manager->expects($this->once())
            ->method('runCron');

        $command = new FileLinkUsageCommands($manager);
        $command->setLogger(new DrushLoggerManager());

        $command->scan();
    }
}
