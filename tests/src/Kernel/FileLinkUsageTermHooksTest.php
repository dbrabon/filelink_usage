<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;

/**
 * Tests scanning taxonomy term descriptions for file links.
 *
 * @group filelink_usage
 */
class FileLinkUsageTermHooksTest extends FileLinkUsageKernelTestBase {

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
    'filelink_usage',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('taxonomy_term');
    $this->installConfig(['taxonomy']);

    Vocabulary::create([
      'vid' => 'tags',
      'name' => 'Tags',
    ])->save();
  }

  /**
   * Ensures term descriptions with links create usage records.
   */
  public function testTermScanRecordsUsage(): void {
    $uri = 'public://term_link.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($uri),
      'txt'
    );
    $file = File::create([
      'uri' => $uri,
      'filename' => 'term_link.txt',
    ]);
    $file->save();

    $term = Term::create([
      'vid' => 'tags',
      'name' => 'Term with link',
      'description' => [
        'value' => '<a href="/sites/default/files/term_link.txt">Download</a>',
        'format' => 'plain_text',
      ],
    ]);
    $term->save();

    // Scan the term to register the link.
    $this->container->get('filelink_usage.scanner')->scan([
      'taxonomy_term' => [$term->id()],
    ]);

    $database = $this->container->get('database');
    $link = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'taxonomy_term')
      ->condition('entity_id', $term->id())
      ->execute()
      ->fetchField();
    $this->assertEquals($uri, $link);

    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($term->id(), $usage['filelink_usage']['taxonomy_term']);
  }

}
