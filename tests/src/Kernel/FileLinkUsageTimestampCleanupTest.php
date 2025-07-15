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

  /**
   * Collects invalidated cache tags.
   */
  protected array $invalidated = [];

  /**
   * Record invalidated cache tags.
   */
  public function recordInvalidatedTags(array $tags): void {
    $this->invalidated = array_merge($this->invalidated, $tags);
  }

  protected function setUp(): void {
    parent::setUp();
    $this->time = new MutableTime(1000);
    $this->container->set('datetime.time', $this->time);

    $this->invalidated = [];
    $invalidator = new class($this) implements \Drupal\Core\Cache\CacheTagsInvalidatorInterface {
      private $test;
      public function __construct($test) { $this->test = $test; }
      public function invalidateTags(array $tags): void { $this->test->recordInvalidatedTags($tags); }
      public function invalidateTagsAsynchronously(array $tags): void { $this->invalidateTags($tags); }
    };
    $this->container->set('cache_tags.invalidator', $invalidator);
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

  /**
   * Adding and removing links updates rows without removing unchanged ones.
   */
  public function testManageUsageUpdatesTimestamps(): void {
    $fs = $this->container->get('file_system');
    $uri1 = 'public://update_ts1.txt';
    $uri2 = 'public://update_ts2.txt';
    $uri3 = 'public://update_ts3.txt';
    file_put_contents($fs->realpath($uri1), 'txt');
    file_put_contents($fs->realpath($uri2), 'txt');
    file_put_contents($fs->realpath($uri3), 'txt');
    $file1 = File::create(['uri' => $uri1, 'filename' => 'update_ts1.txt']);
    $file1->save();
    $file2 = File::create(['uri' => $uri2, 'filename' => 'update_ts2.txt']);
    $file2->save();
    $file3 = File::create(['uri' => $uri3, 'filename' => 'update_ts3.txt']);
    $file3->save();

    $body = implode(' ', [
      '<a href="/sites/default/files/update_ts1.txt">1</a>',
      '<a href="/sites/default/files/update_ts2.txt">2</a>',
    ]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Update TS',
      'body' => ['value' => $body, 'format' => 'basic_html'],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $database = $this->container->get('database');
    $rows = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link', 'timestamp'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()->fetchAllKeyed();
    $this->assertEquals([
      'public://update_ts1.txt' => 1000,
      'public://update_ts2.txt' => 1000,
    ], $rows);

    // Add a third link.
    $this->invalidated = [];
    $this->time->setTime(2000);
    $body2 = implode(' ', [
      '<a href="/sites/default/files/update_ts1.txt">1</a>',
      '<a href="/sites/default/files/update_ts2.txt">2</a>',
      '<a href="/sites/default/files/update_ts3.txt">3</a>',
    ]);
    $node->set('body', ['value' => $body2, 'format' => 'basic_html']);
    $node->save();

    $rows = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link', 'timestamp'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()->fetchAllKeyed();
    $this->assertEquals([
      'public://update_ts1.txt' => 2000,
      'public://update_ts2.txt' => 2000,
      'public://update_ts3.txt' => 2000,
    ], $rows);
    $this->assertContains('file:' . $file3->id(), $this->invalidated);
    $this->assertContains('file_list', $this->invalidated);

    // Remove the second link.
    $this->invalidated = [];
    $this->time->setTime(3000);
    $body3 = implode(' ', [
      '<a href="/sites/default/files/update_ts1.txt">1</a>',
      '<a href="/sites/default/files/update_ts3.txt">3</a>',
    ]);
    $node->set('body', ['value' => $body3, 'format' => 'basic_html']);
    $node->save();

    $rows = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link', 'timestamp'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()->fetchAllKeyed();
    $this->assertEquals([
      'public://update_ts1.txt' => 3000,
      'public://update_ts3.txt' => 3000,
    ], $rows);
    $this->assertContains('file:' . $file2->id(), $this->invalidated);
    $this->assertContains('file_list', $this->invalidated);
  }

}

