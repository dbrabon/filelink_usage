<?php

declare(strict_types=1);

namespace Drupal\Tests\filelink_usage\Unit;

require_once __DIR__ . '/../../../src/FileLinkUsageNormalizer.php';

use Drupal\filelink_usage\FileLinkUsageNormalizer;
use PHPUnit\Framework\TestCase;

class FileLinkUsageNormalizerTest extends TestCase {
    public function testUrlNormalization(): void {
        $normalizer = new FileLinkUsageNormalizer();

        $cases = [
            'https://dev.example.com/sites/default/files/foo/bar.pdf?x=1#sec' => 'public://foo/bar.pdf',
            'http://example.com/system/files//doc.txt?y=2#frag' => 'private://doc.txt',
            '/sites/default/files/My%20File.pdf' => 'public://My File.pdf',
            'https://cdn.example.com/assets/manual.pdf?ver=1' => '/assets/manual.pdf',
        ];

        foreach ($cases as $input => $expected) {
            $this->assertEquals($expected, $normalizer->normalize($input));
        }
    }
}

