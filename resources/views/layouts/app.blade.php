<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', config('app.name'))</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-screen bg-gray-50 text-gray-900 antialiased">
    <header class="sticky top-0 z-10 border-b border-gray-200 bg-white/90 backdrop-blur">
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
            <a href="{{ route('videos.index') }}" class="shrink-0 text-lg font-semibold">{{ config('app.name') }}</a>

            <form method="POST" action="{{ route('videos.store') }}"
                class="w-full min-w-0 max-w-md flex-1 sm:ml-auto sm:flex-none">
                @csrf
                <div class="flex gap-2">
                    <input type="url" name="url" id="url" value="{{ old('url') }}"
                        placeholder="YouTube の URL を貼り付け" required
                        class="min-w-0 flex-1 rounded-full border border-gray-300 px-4 py-1.5 text-sm focus:border-gray-500 focus:outline-none">
                    <button type="submit"
                        class="shrink-0 rounded-full bg-gray-900 px-4 py-1.5 text-sm font-bold text-white hover:bg-gray-700">
                        登録
                    </button>
                </div>
                @error('url')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </form>
        </div>
    </header>

    <main class="mx-auto max-w-6xl px-4 py-8">
        @if (session('status'))
            <div class="mb-6 rounded-full bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>

</html>
