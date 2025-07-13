<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\comment\Entity\Comment;
use Drupal\node\Entity\Node;
use Drupal\comment\Entity\CommentType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;

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

    // Create a basic comment type for nodes.
    CommentType::create([
      'id' => 'comment',
      'label' => 'Comment',
      'target_entity_type_id' => 'node',
    ])->save();
    \Drupal::service('comment.manager')->addBodyField('comment');

    // Attach the comment field to the article content type.
    FieldStorageConfig::create([
      'entity_type' => 'node',
      'field_name' => 'comment',
      'type' => 'comment',
      'settings' => [
        'comment_type' => 'comment',
      ],
    ])->save();
    FieldConfig::create([
      'entity_type' => 'node',
      'bundle' => 'article',
      'field_name' => 'comment',
      'label' => 'Comments',
      'default_value' => [
        [
          'status' => CommentItemInterface::OPEN,
        ],
      ],
    ])->save();
    \Drupal::service('entity_display.repository')
      ->getFormDisplay('node', 'article')
      ->setComponent('comment', [
        'type' => 'comment_default',
        'weight' => 20,
      ])
      ->save();
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('node', 'article')
      ->setComponent('comment', [
        'label' => 'above',
        'type' => 'comment_default',
        'weight' => 20,
        'settings' => ['view_mode' => 'full'],
      ])
      ->save();
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
        'format' => 'basic_html',
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
        'format' => 'basic_html',
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
