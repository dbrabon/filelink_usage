<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Tests timestamp updates and cleanup in manageUsage().
 *
 * @group filelink_usage
 */
class FileLinkUsageTimestampCleanupTest extends FileLinkUsageKernelTestBase {

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

  protected MutableTime $time;

  protected function setUp(): void {
    parent::setUp();
    $this->time = new MutableTime(1000);
    $this->container->set('datetime.time', $this->time);
  }

  /**
   * Ensures timestamps are updated and stale rows removed.
   */
  public function testTimestampCleanup(): void {
    $fs = $this->container->get('file_system');
    $uri1 = 'public://ts1.txt';
    $uri2 = 'public://ts2.txt';
    $uri3 = 'public://ts3.txt';
    file_put_contents($fs->realpath($uri1), 'txt');
    file_put_contents($fs->realpath($uri2), 'txt');
    file_put_contents($fs->realpath($uri3), 'txt');
    $file1 = File::create(['uri' => $uri1, 'filename' => 'ts1.txt']);
    $file1->save();
    $file2 = File::create(['uri' => $uri2, 'filename' => 'ts2.txt']);
    $file2->save();
    $file3 = File::create(['uri' => $uri3, 'filename' => 'ts3.txt']);
    $file3->save();

    $body = implode(' ', [
      '<a href="/sites/default/files/ts1.txt">1</a>',
      '<a href="/sites/default/files/ts2.txt">2</a>',
      '<a href="/sites/default/files/ts3.txt">3</a>',
    ]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'TS',
      'body' => ['value' => $body, 'format' => 'basic_html'],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $database = $this->container->get('database');
    $timestamps = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['timestamp'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()->fetchCol();
    $this->assertEquals([1000, 1000, 1000], $timestamps);

    // Save again without changes; timestamps should update but rows remain.
    $this->time->setTime(2000);
    $node->set('body', ['value' => $body, 'format' => 'basic_html']);
    $node->save();

    $timestamps = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['timestamp'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()->fetchCol();
    $this->assertEquals([2000, 2000, 2000], $timestamps);

    // Remove two links.
    $this->time->setTime(3000);
    $node->set('body', ['value' => '<a href="/sites/default/files/ts1.txt">1</a>', 'format' => 'basic_html']);
    $node->save();

    $links = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()->fetchCol();
    sort($links);
    $this->assertEquals(['public://ts1.txt'], $links);

    $usage1 = $this->container->get('file.usage')->listUsage($file1);
    $this->assertArrayHasKey($node->id(), $usage1['filelink_usage']['node']);
    $usage2 = $this->container->get('file.usage')->listUsage($file2);
    $this->assertEmpty($usage2['filelink_usage']['node'] ?? []);
    $usage3 = $this->container->get('file.usage')->listUsage($file3);
    $this->assertEmpty($usage3['filelink_usage']['node'] ?? []);
  }

}

