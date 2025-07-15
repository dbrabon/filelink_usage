<?php

namespace Drupal\Tests\filelink_usage\Kernel;

use Drupal\Component\Datetime\Time;

class MutableTime extends Time {
    protected int $time;
    public function __construct(int $time) {
        $this->time = $time;
    }
    public function setTime(int $time): void {
        $this->time = $time;
    }
    public function getCurrentTime() {
        return $this->time;
    }
    public function getRequestTime() {
        return $this->time;
    }
}
