<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Ensures large content does not lose usage entries on save.
 *
 * @group filelink_usage
 */
class FileLinkUsageLargeContentTest extends FileLinkUsageKernelTestBase {

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

  protected MutableTime $time;

  protected function setUp(): void {
    parent::setUp();
    $this->time = new MutableTime(1000);
    $this->container->set('datetime.time', $this->time);
  }

  /**
   * Saving large bodies updates timestamps without deleting usage.
   */
  public function testLargeContentPresave(): void {
    $fs = $this->container->get('file_system');
    $links = [];
    $body = [];
    $count = 200;
    for ($i = 1; $i <= $count; $i++) {
      $uri = "public://large{$i}.txt";
      file_put_contents($fs->realpath($uri), 'txt');
      $file = File::create([
        'uri' => $uri,
        'filename' => "large{$i}.txt",
      ]);
      $file->save();
      $links[] = $file;
      $body[] = "<a href=\"/sites/default/files/large{$i}.txt\">L{$i}</a>";
    }

    $node = Node::create([
      'type' => 'article',
      'title' => 'Large',
      'body' => ['value' => implode(' ', $body), 'format' => 'basic_html'],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $database = $this->container->get('database');
    $match_count = $database->select('filelink_usage_matches', 'f')
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($count, $match_count);

    $this->time->setTime(2000);
    $node->set('body', ['value' => implode(' ', $body), 'format' => 'basic_html']);
    $node->save();

    $rows = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link', 'timestamp'])
      ->condition('entity_type', 'node')
      ->condition('entity_id', $node->id())
      ->execute()->fetchAllKeyed();
    $this->assertCount($count, $rows);
    foreach ($rows as $timestamp) {
      $this->assertEquals(2000, (int) $timestamp);
    }

    $usage_count = $database->select('file_usage')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals($count, (int) $usage_count);
  }
}
