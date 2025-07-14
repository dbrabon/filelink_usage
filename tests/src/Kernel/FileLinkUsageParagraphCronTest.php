<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\Tests\filelink_usage\Kernel\FileLinkUsageKernelTestBase;
use Drupal\file\Entity\File;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Ensures paragraphs created before enabling the module are scanned by cron.
 *
 * @group filelink_usage
 */
class FileLinkUsageParagraphCronTest extends FileLinkUsageKernelTestBase {

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
    'paragraphs',
    // filelink_usage installed later in the test.
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('paragraph');
    $this->installConfig(['paragraphs']);

    ParagraphsType::create([
      'id' => 'text',
      'label' => 'Text',
    ])->save();

    FieldStorageConfig::create([
      'entity_type' => 'paragraph',
      'field_name' => 'field_body',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'entity_type' => 'paragraph',
      'bundle' => 'text',
      'field_name' => 'field_body',
      'label' => 'Body',
    ])->save();
    \Drupal::service('entity_display.repository')
      ->getViewDisplay('paragraph', 'text')
      ->setComponent('field_body', [
        'type' => 'text_default',
      ])
      ->save();
  }

  /**
   * Paragraph links are detected when cron runs after enabling the module.
   */
  public function testParagraphScannedDuringCron(): void {
    $uri = 'public://para.txt';
    file_put_contents(
      $this->container->get('file_system')->realpath($uri),
      'txt'
    );
    $file = File::create([
      'uri' => $uri,
      'filename' => 'para.txt',
    ]);
    $file->save();

    $paragraph = Paragraph::create([
      'type' => 'text',
      'field_body' => [
        'value' => '<a href="/sites/default/files/para.txt">Download</a>',
        'format' => 'basic_html',
      ],
    ]);
    $paragraph->save();

    \Drupal::service('module_installer')->install(['filelink_usage']);
    $this->container = \Drupal::getContainer();

    $this->container->get('filelink_usage.manager')->runCron();

    $database = $this->container->get('database');
    $link = $database->select('filelink_usage_matches', 'f')
      ->fields('f', ['link'])
      ->condition('entity_type', 'paragraph')
      ->condition('entity_id', $paragraph->id())
      ->execute()
      ->fetchField();
    $this->assertEquals($uri, $link);
    $usage = $this->container->get('file.usage')->listUsage($file);
    $this->assertArrayHasKey($paragraph->id(), $usage['filelink_usage']['paragraph']);
  }

}
