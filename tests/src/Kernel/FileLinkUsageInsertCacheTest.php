<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;

/**
 * Ensures cache tags are invalidated when a node is created.
 *
 * @group filelink_usage
 */
class FileLinkUsageInsertCacheTest extends FileLinkUsageKernelTestBase {

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
   * Collected invalidated cache tags.
   */
  protected array $invalidated = [];

  /**
   * Helper to record invalidated tags.
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
   * Saving a new node invalidates cache tags for its linked files.
   */
  public function testInsertInvalidatesTags(): void {
    $uri = 'public://insert_cache.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($uri),
      'txt'
    );
    $file = File::create([
      'uri' => $uri,
      'filename' => 'insert_cache.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/insert_cache.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Insert cache',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    // Usage should be recorded and cache tags invalidated.
    $this->assertContains('file:' . $file->id(), $this->invalidated);
    $this->assertContains('file_list', $this->invalidated);
  }

}
