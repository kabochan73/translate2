{{-- 取り込み進捗ステッパー（FR-7）。終了状態でない時だけ表示する。 --}}
<div
    x-data="ingestProgress({{ $video->id }}, '{{ $video->status->value }}', {{ $video->status->step() }})"
    x-init="init()"
    class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-4"
>
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-2 text-xs">
        @foreach (['準備', '情報取得', '字幕取得', '要約'] as $i => $label)
            @php($n = $i + 1)
            <li class="flex items-center gap-1.5">
                <span
                    class="flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold transition-colors"
                    :class="step > {{ $n }}
                        ? 'bg-emerald-500 text-white'
                        : (step === {{ $n }} ? 'bg-gray-900 text-white' : 'bg-gray-200 text-gray-500')"
                >{{ $n }}</span>
                <span :class="step >= {{ $n }} ? 'font-medium text-gray-900' : 'text-gray-400'">{{ $label }}</span>
            </li>
            @unless ($loop->last)
                <li class="h-px w-6 bg-gray-300" aria-hidden="true"></li>
            @endunless
        @endforeach
    </ol>

    <p class="mt-3 text-xs text-gray-500">
        <span x-show="!isTerminal()">バックグラウンドで処理中です。完了すると自動で切り替わります。</span>
    </p>
</div>
