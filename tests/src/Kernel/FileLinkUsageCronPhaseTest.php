<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Verifies cron scanning phases across multiple runs.
 *
 * @group filelink_usage
 */
class FileLinkUsageCronPhaseTest extends FileLinkUsageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'file',
    'node',
    'filelink_usage',
  ];

  /**
   * Tests cron scan phases populate and clean up usage.
   */
  public function testCronScanPhases(): void {
    $uri = 'public://cron_phase.txt';
    $body = '<a href="/sites/default/files/cron_phase.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Cron phases',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    $manager = $this->container->get('filelink_usage.manager');
    $database = $this->container->get('database');

    // First pass - matches recorded but usage missing until file exists.
    $manager->runCron();
    $link = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()
      ->fetchField();
    $this->assertEquals($uri, $link);
    $count = $database->select('file_usage')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);

    // Create managed file and run cron again - usage should be recorded.
    file_put_contents(
      $this->container->get('file_system')->realpath($uri),
      'txt'
    );
    $file = File::create([
      'uri' => $uri,
      'filename' => 'cron_phase.txt',
    ]);
    $file->save();
    $manager->runCron();
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);

    // Remove the link and run cron a third time - usage removed.
    $node->set('body', [
      'value' => 'No link here',
      'format' => 'basic_html',
    ]);
    $node->save();
    $manager->runCron();
    $count = $database->select('filelink_usage_matches')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertEmpty($usage['filelink_usage']['node'] ?? []);
  }

}
