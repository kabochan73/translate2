<?php

namespace Tests\Feature\Jobs;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchVideoMetadata;
use App\Models\Tag;
use App\Models\Video;
use App\Services\YouTubeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class FetchVideoMetadataTest extends TestCase
{
    use RefreshDatabase;

    private function fakeYouTube(array $snippet = [], array $contentDetails = []): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'snippet' => array_merge([
                        'title' => 'Sample title',
                        'channelTitle' => 'Sample channel',
                        'publishedAt' => '2021-06-01T12:00:00Z',
                        'thumbnails' => ['high' => ['url' => 'https://img/high.jpg']],
                        'defaultAudioLanguage' => 'en',
                        'tags' => ['Laravel', 'PHP'],
                    ], $snippet),
                    'contentDetails' => array_merge(['duration' => 'PT10M'], $contentDetails),
                ]],
            ]),
        ]);
    }

    private function runJob(Video $video): void
    {
        (new FetchVideoMetadata($video))->handle(new YouTubeService('test-key'));
    }

    public function test_it_fills_the_video_and_syncs_tags(): void
    {
        $this->fakeYouTube();
        $video = Video::create(['youtube_id' => 'abc12345678', 'url' => 'https://youtu.be/abc12345678']);

        $this->runJob($video);
        $video->refresh();

        $this->assertSame('Sample title', $video->title);
        $this->assertSame('Sample channel', $video->channel_name);
        $this->assertSame('https://img/high.jpg', $video->thumbnail_url);
        $this->assertSame(600, $video->duration_seconds);
        $this->assertSame('2021-06-01', $video->published_at->toDateString());
        $this->assertSame('en', $video->source_language);
        $this->assertSame(ProcessingStatus::FetchingMetadata, $video->status);
        $this->assertEqualsCanonicalizing(['Laravel', 'PHP'], $video->tags->pluck('name')->all());
    }

    public function test_it_reuses_existing_tags_and_ignores_blank_ones(): void
    {
        Tag::create(['name' => 'Laravel']);
        $this->fakeYouTube(['tags' => ['Laravel', '  ', 'PHP', 'PHP']]);
        $video = Video::create(['youtube_id' => 'abc12345678', 'url' => 'https://youtu.be/abc12345678']);

        $this->runJob($video);

        $this->assertSame(2, Tag::count());
        $this->assertEqualsCanonicalizing(['Laravel', 'PHP'], $video->tags->pluck('name')->all());
    }

    public function test_it_handles_a_video_without_tags(): void
    {
        $this->fakeYouTube(['tags' => []]);
        $video = Video::create(['youtube_id' => 'abc12345678', 'url' => 'https://youtu.be/abc12345678']);

        $this->runJob($video);

        $this->assertCount(0, $video->tags);
        $this->assertSame(0, Tag::count());
    }

    public function test_failed_marks_the_video_as_failed(): void
    {
        $video = Video::create(['youtube_id' => 'abc12345678', 'url' => 'https://youtu.be/abc12345678']);

        (new FetchVideoMetadata($video))->failed(new RuntimeException('YouTube exploded'));
        $video->refresh();

        $this->assertSame(ProcessingStatus::Failed, $video->status);
        $this->assertSame('metadata', $video->failed_step);
        $this->assertSame('YouTube exploded', $video->failed_reason);
    }
}
