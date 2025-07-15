<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Ensures cron runs without the Comment module enabled.
 *
 * @group filelink_usage
 */
class FileLinkUsageNoCommentModuleTest extends FileLinkUsageKernelTestBase {

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
   * Verify runCron works when no comment tables exist.
   */
  public function testCronWithoutCommentModule(): void {
    $uri = 'public://no_comment.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($uri),
      'txt'
    );
    $file = File::create([
      'uri' => $uri,
      'filename' => 'no_comment.txt',
    ]);
    $file->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Cron no comment',
      'body' => [
        'value' => '<a href="/sites/default/files/no_comment.txt">Download</a>',
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    $manager = $this->container->get('filelink_usage.manager');
    $manager->runCron();

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
