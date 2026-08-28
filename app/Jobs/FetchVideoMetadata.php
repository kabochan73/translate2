<?php

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Models\Tag;
use App\Models\Video;
use App\Services\YouTubeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * 取り込みチェーンの 1 本目。
 *
 * YouTube からメタデータを取得して videos を埋め、タグを紐付ける。
 * 責務・共通設定の根拠は design.md §3.2 / §3.3。
 */
class FetchVideoMetadata implements ShouldQueue
{
    use Queueable;

    /** 最大試行回数。 */
    public int $tries = 3;

    /** 1 回の実行のタイムアウト（秒）。 */
    public int $timeout = 120;

    public function __construct(public readonly Video $video) {}

    /**
     * 再試行の待ち時間（秒）: 1 回目失敗→10s, 2 回目→30s, 3 回目→60s。
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(YouTubeService $youtube): void
    {
        $this->video->update(['status' => ProcessingStatus::FetchingMetadata]);

        $data = $youtube->fetchVideoData($this->video->youtube_id);

        $this->video->update([
            'title' => $data['title'],
            'channel_name' => $data['channel_name'],
            'thumbnail_url' => $data['thumbnail_url'],
            'duration_seconds' => $data['duration_seconds'],
            'published_at' => $data['published_at'],
            'source_language' => $data['source_language'],
        ]);

        $this->syncTags($data['tags']);
    }

    /**
     * タグ名の配列を tags テーブルへ正規化して中間表に紐付ける。
     *
     * @param  array<int, string>  $names
     */
    private function syncTags(array $names): void
    {
        $tagIds = collect($names)
            ->map(fn (string $name) => trim($name))
            ->filter()
            ->unique()
            ->map(fn (string $name) => Tag::firstOrCreate(['name' => $name])->id)
            ->all();

        $this->video->tags()->sync($tagIds);
    }

    /**
     * 3 回試行しても失敗した時に 1 度だけ呼ばれる。
     */
    public function failed(Throwable $e): void
    {
        $this->video->update([
            'status' => ProcessingStatus::Failed,
            'failed_step' => 'metadata',
            'failed_reason' => Str::limit($e->getMessage(), 500),
        ]);
    }
}
