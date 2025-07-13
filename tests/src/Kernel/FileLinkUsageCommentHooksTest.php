<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\comment\Entity\Comment;
use Drupal\node\Entity\Node;

/**
 * Tests scanning comment bodies for file links.
 *
 * @group filelink_usage
 */
class FileLinkUsageCommentHooksTest extends FileLinkUsageKernelTestBase {

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
    'comment',
    'filelink_usage',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('comment');
    $this->installConfig(['comment']);
    comment_add_default_field('node', 'article');
  }

  /**
   * Ensures comment bodies with links create usage records.
   */
  public function testCommentScanRecordsUsage(): void {
    $uri = 'public://comment_link.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($uri),
      'txt'
    );
    $file = File::create([
      'uri' => $uri,
      'filename' => 'comment_link.txt',
    ]);
    $file->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Comment parent',
      'body' => [
        'value' => 'Parent node',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $comment = Comment::create([
      'comment_type' => 'comment',
      'entity_type' => 'node',
      'field_name' => 'comment',
      'entity_id' => $node->id(),
      'comment_body' => [
        'value' => '<a href="/sites/default/files/comment_link.txt">Download</a>',
        'format' => 'plain_text',
      ],
    ]);
    $comment->save();

    // Scan the comment to register the link.
    $this->container->get('filelink_usage.scanner')->scan([
      'comment' => [$comment->id()],
    ]);

    $database = $this->container->get('database');
    $link = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'comment')
      ->condition('entity_id', $comment->id())
      ->execute()
      ->fetchField();
    $this->assertEquals($uri, $link);

    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($comment->id(), $usage['filelink_usage']['comment']);
  }

}
