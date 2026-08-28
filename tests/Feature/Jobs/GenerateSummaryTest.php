<?php

namespace Tests\Feature\Jobs;

use App\Enums\ProcessingStatus;
use App\Enums\SummaryStatus;
use App\Jobs\GenerateSummary;
use App\Models\Video;
use App\Services\SummaryGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GenerateSummaryTest extends TestCase
{
    use RefreshDatabase;

    private function videoWithTranscript(): Video
    {
        $video = Video::create(['youtube_id' => 'abc12345678', 'url' => 'https://youtu.be/abc12345678']);
        $video->transcript()->create(['language' => 'en', 'content' => 'the transcript text', 'segments' => []]);

        return $video;
    }

    private function generatorReturning(array $result): SummaryGenerator
    {
        $generator = Mockery::mock(SummaryGenerator::class);
        $generator->allows('generate')->andReturn($result);

        return $generator;
    }

    public function test_it_writes_the_summary_and_completes_the_video(): void
    {
        $video = $this->videoWithTranscript();
        $generator = $this->generatorReturning([
            'content' => '## TL;DR\n要約本文',
            'input_tokens' => 12_000,
            'output_tokens' => 800,
            'prompt_version' => 'v1',
        ]);

        (new GenerateSummary($video))->handle($generator);
        $video->refresh();

        $this->assertSame(ProcessingStatus::Completed, $video->status);

        $summary = $video->summary;
        $this->assertSame(SummaryStatus::Completed, $summary->status);
        $this->assertSame('## TL;DR\n要約本文', $summary->content);
        $this->assertSame('claude-sonnet-5', $summary->model);
        $this->assertSame('v1', $summary->prompt_version);
        $this->assertSame(12_000, $summary->input_tokens);
        $this->assertSame(800, $summary->output_tokens);
        $this->assertNotNull($summary->completed_at);
    }

    public function test_it_estimates_the_cost_from_the_configured_rates(): void
    {
        config(['services.anthropic.input_cost_per_mtok' => 2.0, 'services.anthropic.output_cost_per_mtok' => 10.0]);
        $video = $this->videoWithTranscript();

        (new GenerateSummary($video))->handle($this->generatorReturning([
            'content' => 'x',
            'input_tokens' => 1_000_000,
            'output_tokens' => 1_000_000,
            'prompt_version' => 'v1',
        ]));

        // 1M * $2/M + 1M * $10/M = $12
        $this->assertSame('12.000000', $video->summary->cost_usd);
    }

    public function test_it_overwrites_an_existing_summary_on_retry(): void
    {
        $video = $this->videoWithTranscript();
        $video->summary()->create(['status' => SummaryStatus::Failed, 'language' => 'ja', 'content' => 'old', 'error_message' => 'boom']);

        (new GenerateSummary($video))->handle($this->generatorReturning([
            'content' => 'fresh summary',
            'input_tokens' => 100,
            'output_tokens' => 50,
            'prompt_version' => 'v1',
        ]));

        $this->assertSame(1, $video->summary()->count());
        $summary = $video->summary()->first();
        $this->assertSame('fresh summary', $summary->content);
        $this->assertSame(SummaryStatus::Completed, $summary->status);
        $this->assertNull($summary->error_message);
    }

    public function test_it_throws_when_the_video_has_no_transcript(): void
    {
        $video = Video::create(['youtube_id' => 'abc12345678', 'url' => 'https://youtu.be/abc12345678']);

        $this->expectException(RuntimeException::class);

        (new GenerateSummary($video))->handle($this->generatorReturning(['content' => 'x', 'input_tokens' => 1, 'output_tokens' => 1, 'prompt_version' => 'v1']));
    }

    public function test_failed_marks_both_the_video_and_the_summary(): void
    {
        $video = $this->videoWithTranscript();
        $video->summary()->create(['status' => SummaryStatus::Processing, 'language' => 'ja']);

        (new GenerateSummary($video))->failed(new RuntimeException('Claude overloaded'));
        $video->refresh();

        $this->assertSame(ProcessingStatus::Failed, $video->status);
        $this->assertSame('summary', $video->failed_step);
        $this->assertSame(SummaryStatus::Failed, $video->summary->status);
        $this->assertSame('Claude overloaded', $video->summary->error_message);
    }
}
