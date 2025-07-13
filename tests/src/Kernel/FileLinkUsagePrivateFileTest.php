<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Tests scanning of links to private files.
 *
 * @group filelink_usage
 */
class FileLinkUsagePrivateFileTest extends FileLinkUsageKernelTestBase {

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
   * Tests detection of private file links.
   */
  public function testPrivateFileLink(): void {
    $uri = 'private://secret.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($uri),
      'secret'
    );
    $file = File::create([
      'uri' => $uri,
      'filename' => 'secret.txt',
    ]);
    $file->save();

    $body = '<a href="/system/files/secret.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Private file',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

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

}
