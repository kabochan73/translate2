<?php

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Models\Video;
use App\Services\TranscriptService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * 取り込みチェーンの 2 本目：字幕取得（design.md §3.2 / §4.2）。
 *
 * 字幕なしの分岐（§3.4）は「後続を静的チェーンに入れず、字幕が取れた時だけ
 * ここから GenerateSummary を投げる」方式にした。字幕が無ければチェーンは
 * このジョブで自然に終わる（無駄なジョブが走らない）。
 */
class FetchTranscript implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly Video $video) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    public function handle(TranscriptService $transcripts): void
    {
        $this->video->update(['status' => ProcessingStatus::FetchingTranscript]);

        $data = $transcripts->fetch($this->video->youtube_id, $this->video->source_language);

        // 字幕なし → 要約はスキップして正常終了（§3.4）。
        if ($data === null) {
            $this->video->update(['status' => ProcessingStatus::NoTranscript]);

            return;
        }

        // 再試行で既存字幕を上書きできるよう updateOrCreate（NFR-3）。
        $this->video->transcript()->updateOrCreate([], [
            'language' => $data['language'],
            'content' => $data['content'],
            'segments' => $data['segments'],
        ]);

        GenerateSummary::dispatch($this->video);
    }

    public function failed(Throwable $e): void
    {
        $this->video->update([
            'status' => ProcessingStatus::Failed,
            'failed_step' => 'transcript',
            'failed_reason' => Str::limit($e->getMessage(), 500),
        ]);
    }
}
