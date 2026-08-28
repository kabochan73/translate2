@extends('layouts.app')

@section('title', '動画一覧')

@section('content')
    <h1 class="text-xl font-bold">動画一覧</h1>

    <form method="POST" action="{{ route('videos.store') }}" class="mt-4">
        @csrf
        <div class="flex gap-2">
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

    @if ($videos->isEmpty())
        <p class="mt-12 text-sm text-gray-500">まだ動画がありません。上のフォームから登録してください。</p>
    @else
        <ul class="mt-8 grid grid-cols-1 gap-x-4 gap-y-8 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($videos as $video)
                <li>
                    @include('videos.partials._card', ['video' => $video])
                </li>
            @endforeach
        </ul>

        <div class="mt-12">
            {{ $videos->onEachSide(1)->links() }}
        </div>
    @endif
@endsection
