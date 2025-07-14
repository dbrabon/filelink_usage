<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Tests manageUsage() via save and delete hooks.
 *
 * @group filelink_usage
 */
class FileLinkUsageManageUsageTest extends FileLinkUsageKernelTestBase {

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
   * Updating a node triggers manageUsage via presave.
   */
  public function testNodePresaveUpdatesUsage(): void {
    $uri1 = 'public://manage1.txt';
    $uri2 = 'public://manage2.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri1), 'txt');
    file_put_contents($this->container->get('file_system')->realpath($uri2), 'txt');
    $file1 = File::create(['uri' => $uri1, 'filename' => 'manage1.txt']);
    $file1->save();
    $file2 = File::create(['uri' => $uri2, 'filename' => 'manage2.txt']);
    $file2->save();

    $body = '<a href="/sites/default/files/manage1.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Manage',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    // Initial scan records usage for the first file.
    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);
    $usage1 = $this->container->get('file.usage')->listUsage($file1);
    $this->assertArrayHasKey($node->id(), $usage1['filelink_usage']['node']);

    // Update node to link to the second file and save.
    $node->set('body', [
      'value' => '<a href="/sites/default/files/manage2.txt">Download</a>',
      'format' => 'basic_html',
    ]);
    $node->save();

    // Usage should now reference file2 without rescanning.
    $usage1 = $this->container->get('file.usage')->listUsage($file1);
    $this->assertEmpty($usage1['filelink_usage']['node'] ?? []);
    $usage2 = $this->container->get('file.usage')->listUsage($file2);
    $this->assertArrayHasKey($node->id(), $usage2['filelink_usage']['node']);
  }

  /**
   * Single-quoted links are extracted during presave.
   */
  public function testNodePresaveSingleQuotedLink(): void {
    $uri = 'public://single_presave.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create(['uri' => $uri, 'filename' => 'single_presave.txt']);
    $file->save();

    $body = "<a href='/sites/default/files/single_presave.txt'>Download</a>";
    $node = Node::create([
      'type' => 'article',
      'title' => 'Presave single',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    // Saving the node should record usage via presave without scanning.
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);
  }

  /**
   * Deleting a node removes usage via delete hook.
   */
  public function testNodeDeleteRemovesUsage(): void {
    $uri = 'public://manage_delete.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create(['uri' => $uri, 'filename' => 'manage_delete.txt']);
    $file->save();

    $body = '<a href="/sites/default/files/manage_delete.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Delete',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();
    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($node->id(), $usage['filelink_usage']['node']);

    $node->delete();
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertEmpty($usage['filelink_usage']['node'] ?? []);
    $count = $this->container->get('database')->select('filelink_usage_matches')
      ->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);
  }

}
