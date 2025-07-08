<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;

/**
 * Ensures file usage entries are not duplicated when scanning.
 *
 * @group filelink_usage
 */
class FileLinkUsageScannerTest extends KernelTestBase {

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
   * Tests duplicate usage rows are not created.
   */
  public function testDuplicateFileUsage(): void {
    // Create the file entity.
    $uri = 'public://example.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'example');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'example.txt',
    ]);
    $file->save();

    // Create node with link to the file.
    $body = '<a href="/sites/default/files/example.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test node',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    // Run the scanner twice for the same node.
    $scanner = $this->container->get('filelink_usage.scanner');
    $scanner->scan([$node->id()]);
    $scanner->scan([$node->id()]);

    // Validate only a single usage row exists.
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertCount(1, $usage['filelink_usage']['node']);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

  /**
   * Tests link normalization handles hostnames, slashes and query strings.
   */
  public function testLinkNormalization(): void {
    $uri = 'public://example.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'example');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'example.txt',
    ]);
    $file->save();

    $body = implode(' ', [
      '<a href="https://example.com/sites/default/files//example.txt?foo=1">L1</a>',
      '<a href="/sites/default/files/example.txt">L2</a>'
    ]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test node',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan([$node->id()]);

    $link = $this->container->get('database')->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('nid', $node->id())
      ->execute()
      ->fetchField();

    $this->assertEquals('public://example.txt', $link);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertCount(1, $usage['filelink_usage']['node']);
  }

}

