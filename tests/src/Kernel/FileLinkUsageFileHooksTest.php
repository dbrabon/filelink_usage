<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\Tests\filelink_usage\Kernel\FileLinkUsageKernelTestBase;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Tests file insert hooks create usage for saved links.
 *
 * @group filelink_usage
 */
class FileLinkUsageFileHooksTest extends FileLinkUsageKernelTestBase {

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
        'format' => 'basic_html',
      ],
    ]);
    $node->save();
    $this->container->get('filelink_usage.scanner')
      ->scan(['node' => [$node->id()]]);

    $database = $this->container->get('database');
    $count = $database->select('filelink_usage_matches')
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $count);

    $usage_count = $database->select('file_usage', 'fu')
      ->condition('id', $node->id())
      ->countQuery()
      ->execute()
      ->fetchField();
    $this->assertEquals(0, $usage_count);

    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'hook_file.txt',
    ]);
    $file->save();
    $this->container->get('filelink_usage.scanner')
      ->scan(['node' => [$node->id()]]);

    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

}
