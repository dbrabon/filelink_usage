<?php

declare(strict_types=1);

namespace Drupal\Tests\filelink_usage\Unit;

require_once __DIR__ . '/../../../src/FileLinkUsageNormalizer.php';

use Drupal\filelink_usage\FileLinkUsageNormalizer;
use PHPUnit\Framework\TestCase;

class FileLinkUsageNormalizerTest extends TestCase {
    public function testUrlNormalization(): void {
        $normalizer = new FileLinkUsageNormalizer();
        $url = 'https://dev.example.com/sites/default/files/foo/bar.pdf?x=1#sec';
        $this->assertEquals('public://foo/bar.pdf', $normalizer->normalize($url));
    }
}

