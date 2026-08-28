<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchTranscript;
use App\Jobs\FetchVideoMetadata;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class VideoControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_the_video_list(): void
    {
        $this->get('/')->assertRedirect('/videos');
    }

    public function test_index_renders(): void
    {
        $this->get(route('videos.index'))->assertOk()->assertSee('動画一覧');
    }

    public function test_index_paginates_at_18_per_page(): void
    {
        foreach (range(1, 20) as $i) {
            Video::create([
                'youtube_id' => 'yt'.str_pad((string) $i, 9, '0', STR_PAD_LEFT),
                'url' => "https://youtu.be/yt{$i}",
            ]);
        }

        $videos = $this->get(route('videos.index'))->assertOk()->viewData('videos');

        $this->assertCount(18, $videos);
        $this->assertTrue($videos->hasMorePages());
        $this->assertCount(2, $this->get(route('videos.index', ['page' => 2]))->viewData('videos'));
    }

    public function test_store_creates_a_pending_video_and_dispatches_the_chain(): void
    {
        Bus::fake();

        $response = $this->post(route('videos.store'), [
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $video = Video::sole();
        $this->assertSame('dQw4w9WgXcQ', $video->youtube_id);
        $this->assertSame(ProcessingStatus::Pending, $video->status);
        $response->assertRedirect(route('videos.show', $video));

        Bus::assertChained([
            FetchVideoMetadata::class,
            FetchTranscript::class,
        ]);
    }

    public function test_store_rejects_a_non_youtube_url(): void
    {
        Bus::fake();

        $response = $this->from(route('videos.index'))->post(route('videos.store'), [
            'url' => 'https://example.com/watch?v=dQw4w9WgXcQ',
        ]);

        $response->assertRedirect(route('videos.index'));
        $response->assertSessionHasErrors('url');
        $this->assertSame(0, Video::count());
        Bus::assertNothingDispatched();
    }

    public function test_store_does_not_duplicate_an_existing_video(): void
    {
        Bus::fake();
        $existing = Video::create([
            'youtube_id' => 'dQw4w9WgXcQ',
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
            'status' => ProcessingStatus::Completed,
        ]);

        $response = $this->post(route('videos.store'), [
            'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        ]);

        $response->assertRedirect(route('videos.show', $existing));
        $this->assertSame(1, Video::count());
        Bus::assertNothingDispatched();
    }

    public function test_show_renders(): void
    {
        $video = Video::create([
            'youtube_id' => 'dQw4w9WgXcQ',
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
            'title' => 'Some title',
        ]);

        $this->get(route('videos.show', $video))->assertOk()->assertSee('Some title');
    }

    public function test_retry_from_a_failed_video_resets_it_and_redispatches_the_chain(): void
    {
        Bus::fake();
        $video = Video::create([
            'youtube_id' => 'dQw4w9WgXcQ',
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
            'status' => ProcessingStatus::Failed,
            'failed_step' => 'metadata',
            'failed_reason' => '404',
        ]);

        $this->post(route('videos.retry', $video))->assertRedirect(route('videos.show', $video));

        $video->refresh();
        $this->assertSame(ProcessingStatus::Pending, $video->status);
        $this->assertNull($video->failed_step);
        $this->assertNull($video->failed_reason);
        Bus::assertChained([FetchVideoMetadata::class, FetchTranscript::class]);
    }

    public function test_retry_is_allowed_from_a_completed_video(): void
    {
        Bus::fake();
        $video = Video::create([
            'youtube_id' => 'dQw4w9WgXcQ',
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
            'status' => ProcessingStatus::Completed,
        ]);

        $this->post(route('videos.retry', $video));

        $this->assertSame(ProcessingStatus::Pending, $video->refresh()->status);
        Bus::assertChained([FetchVideoMetadata::class, FetchTranscript::class]);
    }

    public function test_retry_is_rejected_while_the_video_is_still_processing(): void
    {
        Bus::fake();
        $video = Video::create([
            'youtube_id' => 'dQw4w9WgXcQ',
            'url' => 'https://youtu.be/dQw4w9WgXcQ',
            'status' => ProcessingStatus::Summarizing,
        ]);

        $this->post(route('videos.retry', $video))->assertRedirect(route('videos.show', $video));

        $this->assertSame(ProcessingStatus::Summarizing, $video->refresh()->status);
        Bus::assertNothingDispatched();
    }
}
