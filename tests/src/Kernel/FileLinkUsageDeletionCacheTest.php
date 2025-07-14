<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * Verifies cache tag invalidation when deleting a node.
 *
 * @group filelink_usage
 */
class FileLinkUsageDeletionCacheTest extends FileLinkUsageKernelTestBase {

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
   * Invalidated cache tags.
   */
  protected array $invalidated = [];

  /**
   * Record invalidated tags.
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
    $invalidator = new class($this) implements CacheTagsInvalidatorInterface {
      private $test;
      public function __construct($test) { $this->test = $test; }
      public function invalidateTags(array $tags): void { $this->test->recordInvalidatedTags($tags); }
      public function invalidateTagsAsynchronously(array $tags): void { $this->invalidateTags($tags); }
    };
    $this->container->set('cache_tags.invalidator', $invalidator);
  }

  /**
   * Deleting a node invalidates cache tags for file usage.
   */
  public function testNodeDeletionInvalidatesFileTags(): void {
    $uri = 'public://deletion_cache.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($uri),
      'txt'
    );
    $file = File::create([
      'uri' => $uri,
      'filename' => 'deletion_cache.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/deletion_cache.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Deletion cache',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    // Scan so usage exists before deletion.
    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    // Reset to capture only deletion invalidation.
    $this->invalidated = [];

    $node->delete();

    $this->assertContains('file:' . $file->id(), $this->invalidated);
    $this->assertContains('entity_list:file', $this->invalidated);
  }

}
