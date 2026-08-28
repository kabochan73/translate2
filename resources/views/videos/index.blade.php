@extends('layouts.app')

@section('title', '動画一覧')

@section('content')

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
            {{ $videos->links() }}
        </div>
    @endif
@endsection
