<?php

namespace Tests\Feature\Jobs;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchTranscript;
use App\Jobs\GenerateSummary;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * フェーズ3 時点の骨格ジョブ（FetchTranscript / GenerateSummary）のテスト。
 * 実処理はフェーズ4 で足すが、status 遷移と failed() の挙動は今から確定させる。
 */
class IngestionStubJobsTest extends TestCase
{
    use RefreshDatabase;

    private function video(): Video
    {
        return Video::create(['youtube_id' => 'abc12345678', 'url' => 'https://youtu.be/abc12345678']);
    }

    public function test_fetch_transcript_moves_status_forward(): void
    {
        $video = $this->video();

        (new FetchTranscript($video))->handle();

        $this->assertSame(ProcessingStatus::FetchingTranscript, $video->refresh()->status);
    }

    public function test_fetch_transcript_failed_marks_transcript_step(): void
    {
        $video = $this->video();

        (new FetchTranscript($video))->failed(new RuntimeException('no captions endpoint'));
        $video->refresh();

        $this->assertSame(ProcessingStatus::Failed, $video->status);
        $this->assertSame('transcript', $video->failed_step);
        $this->assertSame('no captions endpoint', $video->failed_reason);
    }

    public function test_generate_summary_completes_the_video(): void
    {
        $video = $this->video();

        (new GenerateSummary($video))->handle();

        $this->assertSame(ProcessingStatus::Completed, $video->refresh()->status);
        $this->assertTrue($video->status->isTerminal());
    }

    public function test_generate_summary_failed_marks_summary_step(): void
    {
        $video = $this->video();

        (new GenerateSummary($video))->failed(new RuntimeException('Claude 500'));
        $video->refresh();

        $this->assertSame(ProcessingStatus::Failed, $video->status);
        $this->assertSame('summary', $video->failed_step);
    }
}
