<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Tests automatic scanning via node insert and update hooks.
 *
 * @group filelink_usage
 */
class FileLinkUsageNodeHooksTest extends KernelTestBase {

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
    $this->installConfig(['node', 'filter']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Ensures node insert triggers scanning of hard-coded links.
   */
  public function testInsertHookScansNode(): void {
    $uri = 'public://hook_insert.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'hook_insert.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/hook_insert.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Hook insert',
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
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

  /**
   * Ensures node update rescans content when links change.
   */
  public function testUpdateHookScansNode(): void {
    $uri = 'public://hook_update.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'hook_update.txt',
    ]);
    $file->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Hook update',
      'body' => [
        'value' => 'Initial body',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $database = $this->container->get('database');
    $count = $database->select('filelink_usage_matches')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);

    $node->body->value = '<a href="/sites/default/files/hook_update.txt">Download</a>';
    $node->save();

    $link = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()
      ->fetchField();
    $this->assertEquals($uri, $link);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

  /**
   * Changing a link replaces usage from the old file to the new file.
   */
  public function testUpdateReplacesFileUsage(): void {
    $uri1 = 'public://hook_replace1.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri1), 'txt');
    $file1 = File::create([
      'uri' => $uri1,
      'filename' => 'hook_replace1.txt',
    ]);
    $file1->save();

    $uri2 = 'public://hook_replace2.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri2), 'txt');
    $file2 = File::create([
      'uri' => $uri2,
      'filename' => 'hook_replace2.txt',
    ]);
    $file2->save();

    $body = '<a href="/sites/default/files/hook_replace1.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Replace usage',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    // Usage should reference the first file.
    $usage1 = $this->container->get('file.usage')->listUsage($file1);
    $this->assertArrayHasKey($node->id(), $usage1['filelink_usage']['node']);

    // Update to link to the second file.
    $node->body->value = '<a href="/sites/default/files/hook_replace2.txt">Download</a>';
    $node->save();

    $usage1 = $this->container->get('file.usage')->listUsage($file1);
    $this->assertEmpty($usage1['filelink_usage']['node'] ?? []);
    $usage2 = $this->container->get('file.usage')->listUsage($file2);
    $this->assertArrayHasKey($node->id(), $usage2['filelink_usage']['node']);
  }

  /**
   * Ensures node delete removes usage entries via entity_delete().
   */
  public function testDeleteHookRemovesUsage(): void {
    $uri = 'public://hook_delete.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'hook_delete.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/hook_delete.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Hook delete',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    // Usage should be added on save via insert hook.
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);

    $node->delete();

    $database = $this->container->get('database');
    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertEmpty($usage['filelink_usage']['node'] ?? []);
  }

}

