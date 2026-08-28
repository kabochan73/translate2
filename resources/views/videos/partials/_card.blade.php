@use('App\Enums\ProcessingStatus')

<a href="{{ route('videos.show', $video) }}" class="group block">
    <div class="relative aspect-video overflow-hidden bg-gray-200">
        @if ($video->thumbnail_url)
            <img src="{{ $video->thumbnail_url }}" alt="" loading="lazy"
                class="h-full w-full object-cover transition duration-200 group-hover:scale-[1.03]">
        @endif

        @if ($video->duration_label)
            <span class="absolute bottom-1.5 right-1.5 rounded bg-black/80 px-1.5 py-0.5 text-xs font-medium text-white">
                {{ $video->duration_label }}
            </span>
        @endif

        @unless ($video->status === ProcessingStatus::Completed)
            <span @class([
                'absolute left-1.5 top-1.5 rounded px-1.5 py-0.5 text-xs font-medium',
                'bg-red-600 text-white' => $video->status === ProcessingStatus::Failed,
                'bg-white/90 text-gray-800' => $video->status !== ProcessingStatus::Failed,
            ])>
                {{ $video->status->label() }}
            </span>
        @endunless
    </div>

    <div class="mt-2.5">
        <h3 class="line-clamp-2 text-sm font-bold leading-snug text-gray-900">
            {{ $video->title ?? $video->url }}
        </h3>
        <p class="mt-1 text-xs text-gray-600">{{ $video->channel_name }}</p>
        @if ($video->published_at)
            <p class="text-xs text-gray-500">{{ $video->published_at->diffForHumans() }}</p>
        @endif
    </div>
</a>
