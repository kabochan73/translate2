<?php

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Models\Video;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * 取り込みチェーンの 2 本目：字幕取得。
 *
 * ⚠️ フェーズ3 時点では骨格のみ。status を進めるだけで字幕は取らない。
 * 実処理（TranscriptService・字幕なしの分岐 §3.4）はフェーズ4 で実装する。
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

    public function handle(): void
    {
        $this->video->update(['status' => ProcessingStatus::FetchingTranscript]);

        // TODO(フェーズ4): TranscriptService で字幕取得 → transcripts を updateOrCreate。
        //   字幕が無ければ status = NoTranscript にして後続（GenerateSummary）を止める（design.md §3.4）。
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
