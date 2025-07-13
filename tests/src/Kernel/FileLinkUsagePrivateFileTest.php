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
   * Temporary directory for private files.
   *
   * @var string
   */
  protected string $privateDirectory;

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

    // Create a temporary directory for private files.
    $this->privateDirectory = sys_get_temp_dir() . '/filelink_usage_private_' . uniqid();
    if (!file_exists($this->privateDirectory)) {
      mkdir($this->privateDirectory, 0777, TRUE);
    }

    // Configure Drupal to use this directory for the private file system.
    $this->config('system.file')
      ->set('path.private', $this->privateDirectory)
      ->save();
  }

  /**
   * Tests detection of private file links.
   */
  public function testPrivateFileLink(): void {
    $uri = 'private://secret.txt';
    $fs = $this->container->get('file_system');
    $directory = $fs->realpath('private://');
    if (!file_exists($directory)) {
      mkdir($directory, 0777, TRUE);
    }
    file_put_contents(
      $fs->realpath($uri),
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
        'format' => 'basic_html',
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
