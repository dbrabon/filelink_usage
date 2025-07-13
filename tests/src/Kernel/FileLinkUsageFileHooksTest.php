<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests file insert hooks create usage for saved links.
 *
 * @group filelink_usage
 */
class FileLinkUsageFileHooksTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('filelink_usage', [
      'filelink_usage_matches',
      'filelink_usage_scan_status',
    ]);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'filter']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Ensures file entity creation adds usage for saved links.
   */
  public function testFileInsertAddsUsage(): void {
    $uri = 'public://hook_file.txt';
    $body = '<a href="/sites/default/files/hook_file.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'File hook',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $database = $this->container->get('database');
    $link = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()
      ->fetchField();
    $this->assertEquals($uri, $link);

    $usage_count = $database->select('file_usage', 'fu')
      ->countQuery()
      ->condition('id', $node->id())
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $usage_count);

    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'hook_file.txt',
    ]);
    $file->save();

    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

}
