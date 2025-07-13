<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\Tests\filelink_usage\Kernel\FileLinkUsageKernelTestBase;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\comment\Entity\Comment;

/**
 * Ensures file usage entries are not duplicated when scanning.
 *
 * @group filelink_usage
 */
class FileLinkUsageScannerTest extends FileLinkUsageKernelTestBase {

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
    'taxonomy',
    'comment',
    'filelink_usage',
  ];

  /**
   * {@inheritdoc}
  */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('comment');
    comment_add_default_field('node', 'article');
  }

  /**
   * Tests duplicate usage rows are not created.
   */
  public function testDuplicateFileUsage(): void {
    // Create the file entity.
    $uri = 'public://example.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'example');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'example.txt',
    ]);
    $file->save();

    // Create node with link to the file.
    $body = '<a href="/sites/default/files/example.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test node',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    // Run the scanner twice for the same node.
    $scanner = $this->container->get('filelink_usage.scanner');
    $scanner->scan(['node' => [$node->id()]]);
    $scanner->scan(['node' => [$node->id()]]);

    // Validate only a single usage row exists.
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertCount(1, $usage['filelink_usage']['node']);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

  /**
   * Tests link normalization handles hostnames, slashes and query strings.
   */
  public function testLinkNormalization(): void {
    $uri = 'public://example.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'example');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'example.txt',
    ]);
    $file->save();

    $body = implode(' ', [
      '<a href="https://example.com/sites/default/files//example.txt?foo=1">L1</a>',
      '<a href="/sites/default/files/example.txt">L2</a>'
    ]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test node',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $link = $this->container->get('database')->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()
      ->fetchField();

    $this->assertEquals('public://example.txt', $link);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertCount(1, $usage['filelink_usage']['node']);
  }

  /**
   * Tests detection of percent-encoded and space-containing paths.
   */
  public function testSpaceAndPercentEncodedLinks(): void {
    $uri1 = 'public://My File.pdf';
    file_put_contents($this->container->get('file_system')->realpath($uri1), 'pdf');
    $file1 = File::create([
      'uri' => $uri1,
      'filename' => 'My File.pdf',
    ]);
    $file1->save();

    $uri2 = 'public://my file.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri2), 'txt');
    $file2 = File::create([
      'uri' => $uri2,
      'filename' => 'my file.txt',
    ]);
    $file2->save();

    $body = implode(' ', [
      '<a href="/sites/default/files/My%20File.pdf">PDF</a>',
      '<a href="/sites/default/files/my file.txt">TXT</a>',
    ]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test node spaces',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $links = $this->container->get('database')->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()
      ->fetchCol();

    sort($links);
    $this->assertEquals([
      'public://My File.pdf',
      'public://my file.txt',
    ], $links);

    $usage1 = $this->container->get('file.usage')->listUsage($file1);
    $this->assertArrayHasKey($node->id(), $usage1['filelink_usage']['node']);
    $usage2 = $this->container->get('file.usage')->listUsage($file2);
    $this->assertArrayHasKey($node->id(), $usage2['filelink_usage']['node']);
  }

  /**
   * Tests scanning of links contained in text summaries.
   */
  public function testSummaryLinksDetected(): void {
    $uri = 'public://summary.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'summary.txt',
    ]);
    $file->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Summary links',
      'body' => [
        'value' => 'No link here',
        'summary' => '<a href="/sites/default/files/summary.txt">Download</a>',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $link = $this->container->get('database')->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()
      ->fetchField();

    $this->assertEquals('public://summary.txt', $link);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

  /**
   * Purging matches and rescanning repopulates the table.
   */
  public function testScannerRepopulatesAfterTruncate(): void {
    $uri = 'public://repop.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'repop.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/repop.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Repopulate',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $scanner = $this->container->get('filelink_usage.scanner');
    $scanner->scan(['node' => [$node->id()]]);

    $database = $this->container->get('database');
    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, $count);

    $database->truncate('filelink_usage_matches')->execute();
    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);

    $scanner->scan(['node' => [$node->id()]]);
    $link = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()
      ->fetchField();

    $this->assertEquals('public://repop.txt', $link);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

  /**
   * Scanning taxonomy terms records file usage.
   */
  public function testTaxonomyTermScanning(): void {
    $uri = 'public://term.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'term.txt',
    ]);
    $file->save();

    Vocabulary::create(['vid' => 'tags', 'name' => 'Tags'])->save();
    $term = Term::create([
      'name' => 'Test term',
      'vid' => 'tags',
      'description' => [
        'value' => '<a href="/sites/default/files/term.txt">Download</a>',
        'format' => 'plain_text',
      ],
    ]);
    $term->save();

    $this->container->get('filelink_usage.scanner')->scan(['taxonomy_term' => [$term->id()]]);

    $link = $this->container->get('database')->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'taxonomy_term')
      ->condition('entity_id', $term->id())
      ->execute()
      ->fetchField();

    $this->assertEquals($uri, $link);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($term->id(), $usage['filelink_usage']['taxonomy_term']);
  }

  /**
   * Scanning comments records file usage.
   */
  public function testCommentScanning(): void {
    $uri = 'public://comment.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'comment.txt',
    ]);
    $file->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Node with comment',
      'body' => [
        'value' => 'Body',
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $comment = Comment::create([
      'comment_type' => 'comment',
      'entity_type' => 'node',
      'field_name' => 'comment',
      'entity_id' => $node->id(),
      'uid' => 0,
      'subject' => 'Test comment',
      'comment_body' => [
        'value' => '<a href="/sites/default/files/comment.txt">Download</a>',
        'format' => 'plain_text',
      ],
    ]);
    $comment->save();

    $this->container->get('filelink_usage.scanner')->scan(['comment' => [$comment->id()]]);

    $link = $this->container->get('database')->select('filelink_usage_matches', 'f')
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

