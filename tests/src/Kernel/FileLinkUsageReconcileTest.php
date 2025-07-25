<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\Tests\filelink_usage\Kernel\FileLinkUsageKernelTestBase;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;

/**
 * Tests reconciliation of file usage entries.
 *
 * @group filelink_usage
 */
class FileLinkUsageReconcileTest extends FileLinkUsageKernelTestBase {

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
  /**
   * Stale usages are removed and missing ones restored.
   */
  public function testReconcileUsage(): void {
    $uri1 = 'public://reconcile1.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($uri1),
      'txt'
    );
    $file1 = File::create([
      'uri' => $uri1,
      'filename' => 'reconcile1.txt',
    ]);
    $file1->save();

    $uri2 = 'public://reconcile2.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($uri2),
      'txt'
    );
    $file2 = File::create([
      'uri' => $uri2,
      'filename' => 'reconcile2.txt',
    ]);
    $file2->save();

    $body = '<a href="/sites/default/files/reconcile1.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Reconcile',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    // Initial scan.
    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $usage_initial = $this->container->get('file.usage')->listUsage($file1);
    $this->assertArrayHasKey($node->id(), $usage_initial['filelink_usage']['node']);

    $database = $this->container->get('database');
    // Remove usage for the real file and add usage for another file.
    $database->delete('file_usage')
      ->condition('fid', $file1->id())
      ->condition('module', 'filelink_usage')
      ->condition('type', 'node')
      ->condition('id', $node->id())
      ->execute();
    $database->insert('file_usage')
      ->fields([
        'fid' => $file2->id(),
        'module' => 'filelink_usage',
        'type' => 'node',
        'id' => $node->id(),
        'count' => 1,
      ])
      ->execute();

    // Reconcile should restore usage for file1 and remove from file2.
    $this->container->get('filelink_usage.manager')
      ->reconcileEntityUsage('node', $node->id());
    // Apply reconciliation immediately by rescanning the node.
    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $usage1 = $this->container->get('file.usage')->listUsage($file1);
    $this->assertArrayHasKey($node->id(), $usage1['filelink_usage']['node']);
    $usage2 = $this->container->get('file.usage')->listUsage($file2);
    $this->assertEmpty($usage2['filelink_usage']['node'] ?? []);
  }
}

