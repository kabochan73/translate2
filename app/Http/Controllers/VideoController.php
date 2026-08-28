<?php

namespace App\Http\Controllers;

use App\Enums\ProcessingStatus;
use App\Enums\SummaryStatus;
use App\Http\Requests\StoreVideoRequest;
use App\Jobs\FetchTranscript;
use App\Jobs\FetchVideoMetadata;
use App\Models\Video;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Bus;

class VideoController extends Controller
{
    /**
     * 動画一覧（アプリの入口）。
     */
    public function index(): View
    {
        // 3 列グリッド × 6 行。総件数の COUNT を省く simplePaginate（前へ/次へのみ）。
        $videos = Video::with('tags')->latest()->simplePaginate(18);

        return view('videos.index', ['videos' => $videos]);
    }

    /**
     * URL を登録して取り込みチェーンを投入する。
     */
    public function store(StoreVideoRequest $request): RedirectResponse
    {
        $video = Video::firstOrCreate(
            ['youtube_id' => $request->youtubeId()],
            ['url' => $request->string('url'), 'status' => ProcessingStatus::Pending],
        );

        // すでに登録済みなら重複を作らず、その動画へ誘導する（AC-6）。
        if (! $video->wasRecentlyCreated) {
            return redirect()
                ->route('videos.show', $video)
                ->with('status', 'この動画はすでに登録されています。');
        }

        $this->dispatchIngestion($video);

        return redirect()
            ->route('videos.show', $video)
            ->with('status', '取り込みを開始しました。');
    }

    /**
     * 動画詳細。
     */
    public function show(Video $video): View
    {
        $video->load('tags', 'transcript', 'summary');

        return view('videos.show', ['video' => $video]);
    }

    /**
     * 取り込みの進捗を返す軽量 API（FR-7）。詳細ページの Alpine が 3 秒ごとに叩く。
     */
    public function status(Video $video): JsonResponse
    {
        return response()->json([
            'status' => $video->status->value,
            'step' => $video->status->step(),
            'is_terminal' => $video->status->isTerminal(),
            'summary_ready' => $video->summary()
                ->where('status', SummaryStatus::Completed)
                ->exists(),
            'failed_step' => $video->failed_step,
            'failed_reason' => $video->failed_reason,
        ]);
    }

    /**
     * 取り込みを最初からやり直す（design.md §3.5）。
     *
     * 終了状態（completed / no_transcript / failed）からのみ許可する。
     * 既存の transcript / summary は各ジョブが updateOrCreate で上書きする（NFR-3）。
     */
    public function retry(Video $video): RedirectResponse
    {
        if (! $video->status->isTerminal()) {
            return redirect()
                ->route('videos.show', $video)
                ->with('status', 'まだ処理中です。完了してから再試行してください。');
        }

        $video->update([
            'status' => ProcessingStatus::Pending,
            'failed_step' => null,
            'failed_reason' => null,
        ]);

        $this->dispatchIngestion($video);

        return redirect()
            ->route('videos.show', $video)
            ->with('status', '再試行を開始しました。');
    }

    /**
     * 取り込みジョブチェーンをキューに投入する。
     *
     * GenerateSummary は静的チェーンに含めない。字幕が取れた時だけ
     * FetchTranscript が投げる（字幕なしはそこでチェーンが終わる。§3.4）。
     */
    private function dispatchIngestion(Video $video): void
    {
        Bus::chain([
            new FetchVideoMetadata($video),
            new FetchTranscript($video),
        ])->dispatch();
    }
}
