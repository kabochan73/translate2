@extends('layouts.app')

@section('title', $video->title ?? '動画詳細')

@section('content')
    <a href="{{ route('videos.index') }}" class="text-sm text-gray-500 hover:underline">&larr; 一覧へ戻る</a>

    <div class="mt-4 flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold">{{ $video->title ?? '(情報取得前)' }}</h1>
            <p class="mt-1 text-sm text-gray-500">{{ $video->channel_name }}</p>
        </div>
        <span class="rounded-full bg-gray-100 px-3 py-1 text-sm text-gray-700">
            {{ $video->status->label() }}
        </span>
    </div>

    @if ($video->status === \App\Enums\ProcessingStatus::Failed)
        <div class="mt-4 rounded-md bg-red-50 px-4 py-3 text-sm text-red-800">
            {{ $video->failed_step }} の工程で失敗しました：{{ $video->failed_reason }}
        </div>
    @endif

    <div class="mt-6 aspect-video max-w-2xl overflow-hidden rounded-lg bg-gray-100">
        @if ($video->thumbnail_url)
            <img src="{{ $video->thumbnail_url }}" alt="" class="h-full w-full object-cover">
        @endif
    </div>

    @if ($video->tags->isNotEmpty())
        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($video->tags as $tag)
                <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $tag->name }}</span>
            @endforeach
        </div>
    @endif

    <dl class="mt-6 grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:max-w-md">
        <dt class="text-gray-500">元の URL</dt>
        <dd class="truncate"><a href="{{ $video->url }}" class="text-blue-600 hover:underline" target="_blank" rel="noopener">{{ $video->url }}</a></dd>
        <dt class="text-gray-500">再生時間</dt>
        <dd>{{ $video->duration_label ?? '—' }}</dd>
        <dt class="text-gray-500">公開日</dt>
        <dd>{{ $video->published_at?->format('Y-m-d') ?? '—' }}</dd>
    </dl>

    <section class="mt-10">
        <h2 class="text-lg font-semibold">要約</h2>
        <p class="mt-2 text-sm text-gray-500">
            {{-- TODO(フェーズ4): 要約本文・字幕プレイヤーをここに表示する --}}
            字幕取得と要約生成はフェーズ4 で実装します。
        </p>
    </section>
@endsection
