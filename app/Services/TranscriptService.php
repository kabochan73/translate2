<?php

namespace App\Services;

use MrMySQL\YoutubeTranscript\Exception\NoTranscriptAvailableException;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\Transcript;
use MrMySQL\YoutubeTranscript\TranscriptList;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;

/**
 * YouTube の字幕取得ライブラリ（`mrmysql/youtube-transcript`）のラッパー。
 *
 * design.md §4.2。呼び出し元は取り込みジョブ `FetchTranscript`。
 */
class TranscriptService
{
    public function __construct(private readonly TranscriptListFetcher $fetcher) {}

    /**
     * 字幕を取得する。
     *
     * @return array{
     *     language: string,
     *     content: string,
     *     segments: array<int, array{start: float, end: float, text: string}>,
     * }|null  字幕が存在しない動画は null（正常系。要約はスキップ）。
     *
     * 取得の一時的な失敗（レート制限・ブロック・HTTP エラー等）は例外のまま投げる。
     * → ジョブがリトライし、3 回失敗したら `failed`（design.md §8）。
     */
    public function fetch(string $videoId, ?string $preferredLanguage = null): ?array
    {
        try {
            $list = $this->fetcher->fetch($videoId);
            $transcript = $this->pickTranscript($list, $preferredLanguage);
            $entries = $transcript->fetch();
        } catch (NoTranscriptFoundException|NoTranscriptAvailableException|TranscriptsDisabledException) {
            return null;
        }

        $segments = array_map(fn (array $entry): array => [
            'start' => (float) $entry['start'],
            'end' => (float) $entry['start'] + (float) $entry['duration'],
            // 返り値には `&#39;` `&quot;` 等の実体参照が残るので戻す（translate1 と同じ）。
            'text' => html_entity_decode($entry['text'], ENT_QUOTES | ENT_HTML5),
        ], $entries);

        if ($segments === []) {
            return null;
        }

        return [
            'language' => $transcript->language_code,
            'content' => implode(' ', array_column($segments, 'text')),
            'segments' => $segments,
        ];
    }

    /**
     * 優先言語 → そのベース言語（`en-US` → `en`）→ 利用可能な任意、の順で字幕を選ぶ。
     */
    private function pickTranscript(TranscriptList $list, ?string $preferredLanguage): Transcript
    {
        if ($preferredLanguage !== null && $preferredLanguage !== '') {
            $codes = array_values(array_unique([
                $preferredLanguage,
                explode('-', $preferredLanguage)[0],
            ]));

            try {
                return $list->findTranscript($codes);
            } catch (NoTranscriptFoundException) {
                // 優先言語が無ければ下へフォールバック。
            }
        }

        return $list->findTranscript($list->getAvailableLanguageCodes());
    }
}
