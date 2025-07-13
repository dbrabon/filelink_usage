<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\filter\Entity\FilterFormat;

/**
 * Provides common setup for filelink_usage kernel tests.
 */
abstract class FileLinkUsageKernelTestBase extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $this->installEntitySchema('node');
    $this->installSchema('file', ['file_usage']);
    $this->installSchema('filelink_usage', [
      'filelink_usage_matches',
      'filelink_usage_scan_status',
    ]);
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['system', 'node', 'filter']);

    if (!FilterFormat::load('basic_html')) {
      FilterFormat::create([
        'format' => 'basic_html',
        'name' => 'Basic HTML',
      ])->save();
    }

    $node_type = NodeType::create([
      'type' => 'article',
      'name' => 'Article',
    ]);
    $node_type->save();
    node_add_body_field($node_type);
  }

}
