@extends('layouts.app')

@section('title', '動画一覧')

@section('content')
    <h1 class="text-2xl font-bold">動画一覧</h1>

    <form method="POST" action="{{ route('videos.store') }}" class="mt-6">
        @csrf
        <label for="url" class="block text-sm font-medium text-gray-700">YouTube の URL</label>
        <div class="mt-1 flex gap-2">
            <input
                type="url"
                name="url"
                id="url"
                value="{{ old('url') }}"
                placeholder="https://www.youtube.com/watch?v=..."
                required
                class="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-gray-500 focus:outline-none"
            >
            <button type="submit" class="rounded-md bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-gray-700">
                登録
            </button>
        </div>
        @error('url')
            <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
        @enderror
    </form>

    <div class="mt-10">
        @forelse ($videos as $video)
            @if ($loop->first)
                <ul class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @endif
                <li class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                    <a href="{{ route('videos.show', $video) }}" class="block">
                        <div class="aspect-video bg-gray-100">
                            @if ($video->thumbnail_url)
                                <img src="{{ $video->thumbnail_url }}" alt="" class="h-full w-full object-cover">
                            @endif
                        </div>
                        <div class="p-3">
                            <p class="line-clamp-2 text-sm font-medium">
                                {{ $video->title ?? $video->url }}
                            </p>
                            <p class="mt-1 text-xs text-gray-500">{{ $video->channel_name }}</p>
                            <p class="mt-2 inline-block rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">
                                {{ $video->status->label() }}
                            </p>
                        </div>
                    </a>
                </li>
            @if ($loop->last)
                </ul>
            @endif
        @empty
            <p class="text-sm text-gray-500">まだ動画がありません。上のフォームから登録してください。</p>
        @endforelse
    </div>

    <div class="mt-8">
        {{ $videos->links() }}
    </div>
@endsection
