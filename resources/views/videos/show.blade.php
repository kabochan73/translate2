@extends('layouts.app')

@use('App\Enums\ProcessingStatus')
@use('App\Enums\SummaryStatus')

@section('title', $video->title ?? '動画詳細')

@section('content')
    <div class="mx-auto max-w-3xl">
        <a href="{{ route('videos.index') }}" class="text-sm text-gray-500 hover:underline">&larr; 一覧へ戻る</a>

        <div class="mt-3 aspect-video w-full overflow-hidden rounded-xl bg-black">
            <iframe
                src="https://www.youtube.com/embed/{{ $video->youtube_id }}"
                title="{{ $video->title }}"
                class="h-full w-full"
                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
                allowfullscreen
                loading="lazy"
            ></iframe>
        </div>

        <div class="mt-4 flex items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-bold leading-snug">{{ $video->title ?? '(情報取得中)' }}</h1>
                <p class="mt-1 text-sm text-gray-600">{{ $video->channel_name }}</p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <span @class([
                    'rounded-full px-3 py-1 text-sm',
                    'bg-red-100 text-red-700' => $video->status === ProcessingStatus::Failed,
                    'bg-gray-100 text-gray-700' => $video->status !== ProcessingStatus::Failed,
                ])>
                    {{ $video->status->label() }}
                </span>
                @if ($video->status->isTerminal())
                    <form method="POST" action="{{ route('videos.retry', $video) }}">
                        @csrf
                        <button type="submit" class="rounded-full border border-gray-300 px-3 py-1 text-sm text-gray-700 hover:bg-gray-100">
                            再試行
                        </button>
                    </form>
                @endif
            </div>
        </div>

        @if ($video->status === ProcessingStatus::Failed)
            <div class="mt-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-800">
                {{ $video->failed_step }} の工程で失敗しました：{{ $video->failed_reason }}
            </div>
        @endif

        @if ($video->tags->isNotEmpty())
            <div class="mt-4 flex flex-wrap gap-2">
                @foreach ($video->tags as $tag)
                    <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $tag->name }}</span>
                @endforeach
            </div>
        @endif

        <dl class="mt-5 flex flex-wrap gap-x-8 gap-y-2 border-y border-gray-200 py-4 text-sm">
            <div>
                <dt class="text-xs text-gray-500">再生時間</dt>
                <dd>{{ $video->duration_label ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs text-gray-500">公開日</dt>
                <dd>{{ $video->published_at?->format('Y年n月j日') ?? '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-gray-500">元の URL</dt>
                <dd class="truncate">
                    <a href="{{ $video->url }}" class="text-blue-600 hover:underline" target="_blank" rel="noopener">
                        {{ $video->url }}
                    </a>
                </dd>
            </div>
        </dl>

        <section class="mt-8">
            <h2 class="text-lg font-bold">要約</h2>

            @if (! $video->status->isTerminal())
                @include('videos.partials._progress')
            @elseif ($video->summary?->status === SummaryStatus::Completed && $video->summary->content)
                <div class="summary-body mt-3">
                    {!! str($video->summary->content)->markdown(['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                </div>
            @elseif ($video->status === ProcessingStatus::NoTranscript)
                <p class="mt-2 text-sm text-gray-500">この動画には字幕が無いため、要約はありません。</p>
            @elseif ($video->status === ProcessingStatus::Failed)
                <p class="mt-2 text-sm text-red-600">要約の生成に失敗しました。上の「再試行」を押してください。</p>
            @else
                <p class="mt-2 text-sm text-gray-500">まだ要約はありません。</p>
            @endif
        </section>
    </div>
@endsection
