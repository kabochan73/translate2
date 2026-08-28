<?php

namespace Tests\Feature\Jobs;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchTranscript;
use App\Jobs\GenerateSummary;
use App\Models\Video;
use App\Services\TranscriptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class FetchTranscriptTest extends TestCase
{
    use RefreshDatabase;

    private function video(?string $sourceLanguage = 'en'): Video
    {
        return Video::create([
            'youtube_id' => 'abc12345678',
            'url' => 'https://youtu.be/abc12345678',
            'source_language' => $sourceLanguage,
        ]);
    }

    private function serviceReturning(?array $data): TranscriptService
    {
        $service = Mockery::mock(TranscriptService::class);
        $service->allows('fetch')->andReturn($data);

        return $service;
    }

    public function test_it_stores_the_transcript_and_dispatches_the_summary(): void
    {
        Bus::fake();
        $video = $this->video();
        $service = $this->serviceReturning([
            'language' => 'en',
            'content' => 'hello world',
            'segments' => [['start' => 0.0, 'end' => 1.5, 'text' => 'hello world']],
        ]);

        (new FetchTranscript($video))->handle($service);
        $video->refresh();

        $this->assertSame(ProcessingStatus::FetchingTranscript, $video->status);
        $this->assertSame('hello world', $video->transcript->content);
        $this->assertSame('en', $video->transcript->language);
        $this->assertCount(1, $video->transcript->segments);
        Bus::assertDispatched(GenerateSummary::class);
    }

    public function test_no_transcript_ends_the_chain_without_a_summary(): void
    {
        Bus::fake();
        $video = $this->video();

        (new FetchTranscript($video))->handle($this->serviceReturning(null));
        $video->refresh();

        $this->assertSame(ProcessingStatus::NoTranscript, $video->status);
        $this->assertNull($video->transcript);
        Bus::assertNotDispatched(GenerateSummary::class);
    }

    public function test_it_overwrites_an_existing_transcript_on_retry(): void
    {
        Bus::fake();
        $video = $this->video();
        $video->transcript()->create(['language' => 'en', 'content' => 'old', 'segments' => []]);

        (new FetchTranscript($video))->handle($this->serviceReturning([
            'language' => 'ja',
            'content' => 'new',
            'segments' => [['start' => 0.0, 'end' => 1.0, 'text' => 'new']],
        ]));

        $this->assertSame(1, $video->transcript()->count());
        $this->assertSame('new', $video->transcript()->first()->content);
        $this->assertSame('ja', $video->transcript()->first()->language);
    }

    public function test_failed_marks_the_transcript_step(): void
    {
        $video = $this->video();

        (new FetchTranscript($video))->failed(new RuntimeException('rate limited'));
        $video->refresh();

        $this->assertSame(ProcessingStatus::Failed, $video->status);
        $this->assertSame('transcript', $video->failed_step);
        $this->assertSame('rate limited', $video->failed_reason);
    }
}
