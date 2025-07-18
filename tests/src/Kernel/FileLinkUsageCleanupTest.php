<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\Tests\filelink_usage\Kernel\FileLinkUsageKernelTestBase;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Tests cleanup of file link matches when nodes are deleted.
 *
 * @group filelink_usage
 */
class FileLinkUsageCleanupTest extends FileLinkUsageKernelTestBase {

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
   * Ensures cleanupNode removes matches and usage on node deletion.
   */
  public function testCleanupOnNodeDelete(): void {
    $uri = 'public://cleanup.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'cleanup.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/cleanup.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Cleanup node',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $database = $this->container->get('database');
    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, $count);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);

    $node->delete();

    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);
    $count = $database->select('filelink_usage_scan_status')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertEmpty($usage['filelink_usage']['node'] ?? []);
  }

  /**
   * Simulates cleanup when matches already exist in the database.
   */
  public function testManualMatchesCleanupOnNodeDelete(): void {
    $uri = 'public://manual.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'manual.txt',
    ]);
    $file->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Manual matches',
    ]);
    $node->save();

    $database = $this->container->get('database');
    $database->insert('filelink_usage_matches')
      ->fields([
        'entity_type' => 'node',
        'entity_id' => $node->id(),
        'link' => $uri,
        'timestamp' => $this->container->get('datetime.time')->getRequestTime(),
      ])
      ->execute();
    $timestamp = $this->container->get('datetime.time')->getRequestTime();
    $database->merge('filelink_usage_scan_status')
      ->keys([
        'entity_type' => 'node',
        'entity_id' => $node->id(),
      ])
      ->fields([
        'scanned' => $timestamp,
      ])
      ->execute();

    $this->container->get('file.usage')->add($file, 'filelink_usage', 'node', $node->id());

    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertEquals(1, $count);
    $count = $database->select('filelink_usage_scan_status')->countQuery()->execute()->fetchField();
    $this->assertEquals(1, $count);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);

    $node->delete();

    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);
    $count = $database->select('filelink_usage_scan_status')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertEmpty($usage['filelink_usage']['node'] ?? []);
  }

}
