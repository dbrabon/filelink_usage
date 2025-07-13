<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Tests cache tag invalidation when scanning and cleaning up file links.
 *
 * @group filelink_usage
 */
class FileLinkUsageCacheTagsTest extends FileLinkUsageKernelTestBase {

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
   * Records invalidated cache tags.
   */
  protected array $invalidated = [];

  /**
   * Records invalidated cache tags.
   */
  public function recordInvalidatedTags(array $tags): void {
    $this->invalidated = array_merge($this->invalidated, $tags);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->invalidated = [];
    $invalidator = new class($this) {
      private $test;
      public function __construct($test) { $this->test = $test; }
      public function invalidateTags(array $tags): void {
        $this->test->recordInvalidatedTags($tags);
      }
    };
    $this->container->set('cache_tags.invalidator', $invalidator);
  }

  /**
   * Scanning a node invalidates cache tags for newly found files.
   */
  public function testScanningInvalidatesTags(): void {
    $uri = 'public://cache_invalidate.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'cache_invalidate.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/cache_invalidate.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Invalidate',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);
    $this->assertContains('file:' . $file->id(), $this->invalidated);
  }

  /**
   * Removing file usage invalidates cache tags for the removed file.
   */
  public function testCleanupInvalidatesTags(): void {
    $uri = 'public://cleanup_cache.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'cleanup_cache.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/cleanup_cache.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Cleanup',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);
    $this->invalidated = [];

    $this->container->get('filelink_usage.manager')->cleanupNode($node);
    $this->assertContains('file:' . $file->id(), $this->invalidated);
  }

}
