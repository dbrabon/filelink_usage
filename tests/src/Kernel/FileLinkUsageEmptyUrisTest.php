<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Ensures manageUsage() keeps existing rows when given no URIs.
 *
 * @group filelink_usage
 */
class FileLinkUsageEmptyUrisTest extends FileLinkUsageKernelTestBase {

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
   * manageUsage() should return early without removing existing rows.
   */
  public function testEmptyListDoesNotRemoveRows(): void {
    $fs = $this->container->get('file_system');
    $uri = 'public://keep.txt';
    file_put_contents($fs->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'keep.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/keep.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Keep rows',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    // Initial scan to populate tables.
    $this->container->get('filelink_usage.scanner')
      ->scan(['node' => [$node->id()]]);

    $database = $this->container->get('database');
    $count_before = $database->select('filelink_usage_matches')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals(1, (int) $count_before);

    // Simulate extraction failure returning an empty array.
    $this->container->get('filelink_usage.manager')
      ->manageUsage('node', $node->id(), []);

    $count_after = $database->select('filelink_usage_matches')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals(1, (int) $count_after);

    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

}
