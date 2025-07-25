<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Ensures malformed file URIs are normalized when matching managed files.
 *
 * @group filelink_usage
 */
class FileLinkUsageMalformedFileUriTest extends FileLinkUsageKernelTestBase {

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
   * A file with a malformed URI should still be matched during scanning.
   */
  public function testMalformedUriMatchesFile(): void {
    $stored_uri = 'public:///malformed.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($stored_uri),
      'txt'
    );
    $file = File::create([
      'uri' => $stored_uri,
      'filename' => 'malformed.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/malformed.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Malformed',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);

    $row = $this->container->get('database')->select('filelink_usage_matches', 'f')
      ->fields('f', ['link', 'managed_file_uri'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()->fetchObject();
    $this->assertEquals('public://malformed.txt', $row->link);
    $this->assertEquals($stored_uri, $row->managed_file_uri);

    // Running the scan again should not create duplicate rows.
    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);
    $count = $this->container->get('database')->select('filelink_usage_matches')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals(1, $count);
  }

}
