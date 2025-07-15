<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Ensures scanning many entities succeeds using batch loading.
 *
 * @group filelink_usage
 */
class FileLinkUsageLargeBatchScanTest extends FileLinkUsageKernelTestBase {

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
   * Scanning a large set of nodes records all links.
   */
  public function testLargeBatchScan(): void {
    $fs = $this->container->get('file_system');
    $uri = 'public://batch.txt';
    file_put_contents($fs->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'batch.txt',
    ]);
    $file->save();

    $ids = [];
    for ($i = 0; $i < 200; $i++) {
      $node = Node::create([
        'type' => 'article',
        'title' => 'Node ' . $i,
        'body' => [
          'value' => '<a href="/sites/default/files/batch.txt">Link</a>',
          'format' => 'basic_html',
        ],
      ]);
      $node->save();
      $ids[] = $node->id();
    }

    $this->container->get('filelink_usage.scanner')->scan(['node' => $ids]);

    $database = $this->container->get('database');
    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertEquals(count($ids), (int) $count);

    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertCount(count($ids), $usage['filelink_usage']['node']);
  }
}

