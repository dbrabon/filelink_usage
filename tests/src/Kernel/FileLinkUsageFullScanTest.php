<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\Tests\filelink_usage\Kernel\FileLinkUsageKernelTestBase;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\Core\Form\FormState;
use Drupal\filelink_usage\Form\SettingsForm;

/**
 * Tests running a full scan from the settings form.
 *
 * @group filelink_usage
 */
class FileLinkUsageFullScanTest extends FileLinkUsageKernelTestBase {

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
    'block',
    'block_content',
    'taxonomy',
    'comment',
    'filelink_usage',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('block_content');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('comment');
  }

  /**
   * Ensures the full scan button triggers a rescan.
   */
  public function testFullScanTriggersRescan(): void {
    $uri = 'public://example.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'example');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'example.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/example.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test node',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    // Initial scan to populate tables.
    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $database = $this->container->get('database');
    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, $count);
    $count = $database->select('filelink_usage_scan_status')->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, $count);

    // Run the full scan handler.
    $form = SettingsForm::create($this->container);
    $form_state = new FormState();
    $form_array = [];
    $form->runFullScan($form_array, $form_state);

    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, $count);
    $count = $database->select('filelink_usage_scan_status')->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, $count);
    $this->assertGreaterThan(0, $this->config('filelink_usage.settings')->get('last_scan'));

    // runFullScan() already executed the scan and updated timestamps.
  }

  /**
   * Ensures cron performs full rescan when matches table is empty.
   */
  public function testCronRescansWhenMatchesEmpty(): void {
    $uri = 'public://example2.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'ex');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'example2.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/example2.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Test node 2',
      'body' => [
        'value' => $body,
        'format' => 'basic_html',
      ],
    ]);
    $node->save();

    // Initial run via manager to populate and set last_scan.
    $this->container->get('filelink_usage.manager')->runCron();

    $database = $this->container->get('database');
    $database->truncate('filelink_usage_matches')->execute();

    // Cron should detect empty table and rescan.
    $this->container->get('filelink_usage.manager')->runCron();
    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, $count);
  }



}
