<?php

namespace Tests\Unit;

use App\Models\Video;
use PHPUnit\Framework\TestCase;

class VideoTest extends TestCase
{
    public function test_duration_label_formats_minutes_and_seconds(): void
    {
        $this->assertSame('4:13', (new Video(['duration_seconds' => 253]))->duration_label);
    }

    public function test_duration_label_includes_hours_when_over_an_hour(): void
    {
        $this->assertSame('1:02:33', (new Video(['duration_seconds' => 3753]))->duration_label);
    }

    public function test_duration_label_pads_seconds(): void
    {
        $this->assertSame('0:05', (new Video(['duration_seconds' => 5]))->duration_label);
    }

    public function test_duration_label_is_null_when_unknown(): void
    {
        $this->assertNull((new Video)->duration_label);
    }
}
