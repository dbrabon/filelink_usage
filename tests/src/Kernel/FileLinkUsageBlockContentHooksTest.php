<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\Tests\filelink_usage\Kernel\FileLinkUsageKernelTestBase;
use Drupal\file\Entity\File;
use Drupal\block_content\Entity\BlockContent;
use Drupal\block_content\Entity\BlockContentType;

/**
 * Tests scanning of custom blocks via insert hooks.
 *
 * @group filelink_usage
 */
class FileLinkUsageBlockContentHooksTest extends FileLinkUsageKernelTestBase {

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

    $this->installEntitySchema('block_content');
    $this->installConfig(['block_content']);

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
        'format' => 'basic_html',
      ],
    ]);
    $block->save();
    $this->container->get('filelink_usage.scanner')
      ->scan(['block_content' => [$block->id()]]);

    $database = $this->container->get('database');
    $link = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'block')
      ->condition('entity_id', $block->id())
      ->execute()
      ->fetchField();
    $this->assertEquals($uri, $link);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($block->id(), $usage['filelink_usage']['block']);
  }


}

