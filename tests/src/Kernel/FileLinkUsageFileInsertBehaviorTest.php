<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Ensures file insert adds usage for saved links exactly once.
 *
 * @group filelink_usage
 */
class FileLinkUsageFileInsertBehaviorTest extends FileLinkUsageKernelTestBase {

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
   * File insert hook registers usage once for existing matches.
   */
  public function testFileInsertAddsUsageOnce(): void {
    $uri = 'public://insert_once.txt';
    $body = '<a href="/sites/default/files/insert_once.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Insert once',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    // Scan to populate matches without a managed file.
    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);
    $database = $this->container->get('database');
    $count = $database->select('file_usage')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);

    // Create file - insert hook should add usage automatically.
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create(['uri' => $uri, 'filename' => 'insert_once.txt']);
    $file->save();

    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertCount(1, $usage['filelink_usage']['node']);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);

    // Rescanning should not duplicate usage entries.
    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertCount(1, $usage['filelink_usage']['node']);
  }

}
