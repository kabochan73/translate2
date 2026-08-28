<?php

namespace Tests\Feature\Jobs;

use App\Enums\ProcessingStatus;
use App\Jobs\GenerateSummary;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * GenerateSummary はフェーズ4 ステップG まで骨格（status 遷移のみ）。
 * status 遷移と failed() の挙動は今から確定させる。
 */
class GenerateSummaryStubTest extends TestCase
{
    use RefreshDatabase;

    private function video(): Video
    {
        return Video::create(['youtube_id' => 'abc12345678', 'url' => 'https://youtu.be/abc12345678']);
    }

    public function test_it_completes_the_video(): void
    {
        $video = $this->video();

        (new GenerateSummary($video))->handle();

        $this->assertSame(ProcessingStatus::Completed, $video->refresh()->status);
        $this->assertTrue($video->status->isTerminal());
    }

    public function test_failed_marks_the_summary_step(): void
    {
        $video = $this->video();

        (new GenerateSummary($video))->failed(new RuntimeException('Claude 500'));
        $video->refresh();

        $this->assertSame(ProcessingStatus::Failed, $video->status);
        $this->assertSame('summary', $video->failed_step);
    }
}
