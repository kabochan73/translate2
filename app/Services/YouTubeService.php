<?php

namespace App\Services;

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * YouTube Data API v3 との通信をまとめた薄いラッパー。
 *
 * 外部 API を叩くのはこのクラスだけ（design.md §2 の方針）。
 * 呼び出し元は取り込みジョブ（FetchVideoMetadata）。
 */
class YouTubeService
{
    public function __construct(private readonly ?string $apiKey) {}

    /**
     * 各種 YouTube URL から 11 桁の動画 ID を取り出す。
     *
     * 対応形式: watch?v= / youtu.be/ / shorts/ / embed/
     * 取り出せなければ null（呼び出し元が入力エラーにする）。
     */
    public function extractVideoId(string $url): ?string
    {
        $pattern = '#(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})#';

        return preg_match($pattern, $url, $matches) === 1
            ? $matches[1]
            : null;
    }

    /**
     * 動画のメタデータを取得する。
     *
     * @return array{
     *     title: string,
     *     channel_name: string,
     *     thumbnail_url: ?string,
     *     duration_seconds: ?int,
     *     published_at: string,
     *     source_language: ?string,
     *     tags: array<int, string>,
     * }
     *
     * @throws RuntimeException 動画が見つからない場合
     */
    public function fetchVideoData(string $videoId): array
    {
        $response = Http::retry(3, 200)
            ->timeout(15)
            ->get('https://www.googleapis.com/youtube/v3/videos', [
                'id' => $videoId,
                'part' => 'snippet,contentDetails',
                'key' => $this->apiKey,
            ])
            ->throw();

        $item = $response->json('items.0');

        if ($item === null) {
            throw new RuntimeException("YouTube video not found: {$videoId}");
        }

        $snippet = $item['snippet'];
        $duration = $item['contentDetails']['duration'] ?? null;

        return [
            'title' => $snippet['title'],
            'channel_name' => $snippet['channelTitle'],
            'thumbnail_url' => $snippet['thumbnails']['high']['url']
                ?? $snippet['thumbnails']['default']['url']
                ?? null,
            'duration_seconds' => $this->parseDurationSeconds($duration),
            'published_at' => $snippet['publishedAt'],
            'source_language' => $snippet['defaultAudioLanguage']
                ?? $snippet['defaultLanguage']
                ?? null,
            'tags' => $snippet['tags'] ?? [],
        ];
    }

    /**
     * ISO 8601 の期間文字列（例 "PT4M13S"）を秒に直す。
     */
    private function parseDurationSeconds(?string $isoDuration): ?int
    {
        if ($isoDuration === null) {
            return null;
        }

        return (int) CarbonInterval::make($isoDuration)?->totalSeconds;
    }
}
