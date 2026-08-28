<?php

namespace Tests\Feature\Services;

use App\Services\YouTubeService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class YouTubeServiceTest extends TestCase
{
    private function service(): YouTubeService
    {
        return new YouTubeService('test-key');
    }

    #[DataProvider('urlProvider')]
    public function test_extract_video_id_handles_each_url_format(string $url, ?string $expected): void
    {
        $this->assertSame($expected, $this->service()->extractVideoId($url));
    }

    public static function urlProvider(): array
    {
        return [
            'watch?v=' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'youtu.be/' => ['https://youtu.be/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'shorts/' => ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'embed/' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            'watch with extra params' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=10s', 'dQw4w9WgXcQ'],
            'not a youtube url' => ['https://example.com/watch?v=dQw4w9WgXcQ', null],
            'garbage' => ['just some text', null],
        ];
    }

    public function test_fetch_video_data_maps_the_api_response(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'snippet' => [
                        'title' => 'Never Gonna Give You Up',
                        'channelTitle' => 'Rick Astley',
                        'publishedAt' => '2009-10-25T06:57:33Z',
                        'thumbnails' => [
                            'default' => ['url' => 'https://img/default.jpg'],
                            'high' => ['url' => 'https://img/high.jpg'],
                        ],
                        'defaultAudioLanguage' => 'en',
                        'tags' => ['Rick Astley', 'Music'],
                    ],
                    'contentDetails' => ['duration' => 'PT3M33S'],
                ]],
            ]),
        ]);

        $data = $this->service()->fetchVideoData('dQw4w9WgXcQ');

        $this->assertSame('Never Gonna Give You Up', $data['title']);
        $this->assertSame('Rick Astley', $data['channel_name']);
        $this->assertSame('https://img/high.jpg', $data['thumbnail_url']);
        $this->assertSame(213, $data['duration_seconds']);
        $this->assertSame('2009-10-25T06:57:33Z', $data['published_at']);
        $this->assertSame('en', $data['source_language']);
        $this->assertSame(['Rick Astley', 'Music'], $data['tags']);
    }

    public function test_fetch_video_data_defaults_missing_optional_fields(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [[
                    'snippet' => [
                        'title' => 'No frills',
                        'channelTitle' => 'Someone',
                        'publishedAt' => '2020-01-01T00:00:00Z',
                        'thumbnails' => [],
                    ],
                    'contentDetails' => [],
                ]],
            ]),
        ]);

        $data = $this->service()->fetchVideoData('abc12345678');

        $this->assertNull($data['thumbnail_url']);
        $this->assertNull($data['duration_seconds']);
        $this->assertNull($data['source_language']);
        $this->assertSame([], $data['tags']);
    }

    public function test_fetch_video_data_throws_when_video_not_found(): void
    {
        Http::fake([
            'www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => []]),
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->fetchVideoData('missing1234');
    }
}
