<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\file\Entity\File;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Core\Form\FormState;
use Drupal\filelink_usage\Form\SettingsForm;

/**
 * Tests purging saved file links and forcing a rescan via cron.
 *
 * @group filelink_usage
 */
class FileLinkUsagePurgeTest extends KernelTestBase {

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

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installEntitySchema('block_content');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('comment');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('filelink_usage', [
      'filelink_usage_matches',
      'filelink_usage_scan_status',
    ]);
    $this->installConfig(['node', 'filter']);

    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
  }

  /**
   * Ensures purge removes data and cron performs a full rescan.
   */
  public function testPurgeAndCronRescan(): void {
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
        'format' => 'plain_text',
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

    // Call the purge handler.
    $form = SettingsForm::create($this->container);
    $form_state = new FormState();
    $form->purgeFileLinkMatches([], $form_state);

    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);
    $count = $database->select('filelink_usage_scan_status')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);
    $this->assertEquals(0, $this->config('filelink_usage.settings')->get('last_scan'));

    // Run cron which should rescan the node.
    $this->container->get('filelink_usage.manager')->runCron();
    $count = $database->select('filelink_usage_scan_status')->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, $count);
    $this->assertGreaterThan(0, $this->config('filelink_usage.settings')->get('last_scan'));
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
        'format' => 'plain_text',
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


  /**
   * Verifies that cron repopulates matches after purge.
   */
  public function testCronRepopulatesMatchesAfterPurge(): void {
    $uri = 'public://repopulate.txt';
    file_put_contents($this->container->get('file_system')->realpath($uri), 'txt');
    $file = File::create([
      'uri' => $uri,
      'filename' => 'repopulate.txt',
    ]);
    $file->save();

    $body = '<a href="/sites/default/files/repopulate.txt">Download</a>';
    $node = Node::create([
      'type' => 'article',
      'title' => 'Cron repopulate',
      'body' => [
        'value' => $body,
        'format' => 'plain_text',
      ],
    ]);
    $node->save();

    $this->container->get('filelink_usage.scanner')->scan(['node' => [$node->id()]]);

    $database = $this->container->get('database');

    $form = SettingsForm::create($this->container);
    $form_state = new FormState();
    $form->purgeFileLinkMatches([], $form_state);

    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertEquals(0, $count);

    $this->container->get('filelink_usage.manager')->runCron();
    $count = $database->select('filelink_usage_matches')->countQuery()->execute()->fetchField();
    $this->assertGreaterThan(0, $count);
  }

}
