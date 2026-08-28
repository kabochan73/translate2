<?php

namespace Tests\Feature;

use App\Enums\ProcessingStatus;
use App\Jobs\FetchTranscript;
use App\Jobs\FetchVideoMetadata;
use App\Jobs\GenerateSummary;
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
            GenerateSummary::class,
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
}
