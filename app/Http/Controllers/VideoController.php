<?php

namespace App\Http\Controllers;

use App\Enums\ProcessingStatus;
use App\Http\Requests\StoreVideoRequest;
use App\Jobs\FetchTranscript;
use App\Jobs\FetchVideoMetadata;
use App\Jobs\GenerateSummary;
use App\Models\Video;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Bus;

class VideoController extends Controller
{
    /**
     * 動画一覧（アプリの入口）。
     */
    public function index(): View
    {
        $videos = Video::with('tags')->latest()->paginate(12);

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

        Bus::chain([
            new FetchVideoMetadata($video),
            new FetchTranscript($video),
            new GenerateSummary($video),
        ])->dispatch();

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
}
