<?php

namespace App\Jobs;

use App\Enums\ProcessingStatus;
use App\Models\Video;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * 取り込みチェーンの 3 本目：要約生成。
 *
 * ⚠️ フェーズ3 時点では骨格のみ。status を進めて Completed にするだけで
 * 要約は生成しない。実処理（SummaryGenerator・map-reduce・トークン記録）は
 * フェーズ4 で実装する。
 */
class GenerateSummary implements ShouldQueue
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
        $this->video->update(['status' => ProcessingStatus::Summarizing]);

        // TODO(フェーズ4): SummaryGenerator で要約 → summaries を updateOrCreate。

        $this->video->update(['status' => ProcessingStatus::Completed]);
    }

    public function failed(Throwable $e): void
    {
        $this->video->update([
            'status' => ProcessingStatus::Failed,
            'failed_step' => 'summary',
            'failed_reason' => Str::limit($e->getMessage(), 500),
        ]);
    }
}
