<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;
use Drupal\node\Entity\NodeType;

/**
 * Tests automatic scanning via block insert hooks.
 *
 * @group filelink_usage
 */
class FileLinkUsageBlockContentHooksTest extends KernelTestBase {

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
    'block',
    'block_content',
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
    $this->installEntitySchema('block_content');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('filelink_usage', [
      'filelink_usage_matches',
      'filelink_usage_scan_status',
    ]);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'block_content', 'filter']);

    $node_type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $node_type->save();
    node_add_body_field($node_type);
    BlockContentType::create(['id' => 'basic', 'label' => 'Basic'])->save();
  }

  /**
   * Ensures node insert triggers scanning of hard-coded links.
   */
  public function testInsertHookScansBlock(): void {
    $uri = 'public://hook_insert.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'hook_insert.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/hook_insert.txt">Download</a>';
    $block = BlockContent::create([
      'type' => 'basic',
      'info' => 'Hook insert',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $block->save();

    $database = $this->container->get('database');
    $link = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'block_content')
      ->condition('entity_id', $block->id())
      ->execute()
      ->fetchField();
    $this->assertEquals($uri, $link);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($block->id(), $usage['filelink_usage']['block_content']);
  }


}

