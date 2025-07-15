<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Ensures links to non-existent files are ignored until the file exists.
 *
 * @group filelink_usage
 */
class FileLinkUsageMissingFileTest extends FileLinkUsageKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static array $modules = [
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
   * Saving a link to a missing file should not create a matches row.
   */
  public function testMissingFileLinkRecordedAfterScan(): void {
    $uri = 'public://missing.txt';

    $body = '<a href="/sites/default/files/missing.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Missing file',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    $database = $this->container->get('database');
    $count = $database->select('filelink_usage_matches')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals(0, (int) $count);

    file_put_contents(
      $this->container->get('file_system')->realpath($uri),
      'txt'
    );
    $file = File::create([
      'uri' => $uri,
      'filename' => 'missing.txt',
    ]);
    $file->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

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

}

