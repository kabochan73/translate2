<?php

namespace App\Services;

/**
 * 字幕本文から日本語要約を組み立てる（design.md §4.4）。
 *
 * 長い字幕は分割して部分要約 → 統合する（map-reduce）。
 * AnthropicService を使い、usage を合算して返す。
 */
class SummaryGenerator
{
    /** プロンプトを変えたら上げる（要約の品質比較用）。 */
    public const PROMPT_VERSION = 'v1';

    /** 1 チャンクの目安トークン数（design.md §4.4：6〜8k）。 */
    private const CHUNK_TARGET_TOKENS = 6000;

    private const SYSTEM = <<<'TXT'
        あなたは動画の字幕から日本語の要約を作る編集者です。
        字幕の言語が何であっても、出力は必ず日本語にします。
        事実に忠実に、憶測を避け、簡潔にまとめます。
        TXT;

    private const FORMAT = <<<'TXT'

        次の Markdown 構成で出力してください:

        ## TL;DR
        （3〜4 文で全体の要点）

        ## キーポイント
        - （箇条書きで 5〜8 項目）

        ## チャプター別の要約
        （話の流れに沿って見出し + 短い段落。3〜6 個）
        TXT;

    public function __construct(private readonly AnthropicService $anthropic) {}

    /**
     * @return array{content: string, input_tokens: int, output_tokens: int, prompt_version: string}
     */
    public function generate(string $transcriptContent): array
    {
        $chunks = $this->chunk($transcriptContent);

        $inputTokens = 0;
        $outputTokens = 0;

        if (count($chunks) === 1) {
            $final = $this->anthropic->complete(
                self::SYSTEM.self::FORMAT,
                "次の字幕を要約してください:\n\n".$chunks[0],
            );
            $inputTokens += $final['input_tokens'];
            $outputTokens += $final['output_tokens'];

            return $this->result($final['content'], $inputTokens, $outputTokens);
        }

        // map: 各チャンクの要点を抽出
        $partials = [];
        foreach ($chunks as $i => $chunk) {
            $part = $this->anthropic->complete(
                self::SYSTEM,
                sprintf(
                    "次は長い動画の字幕の一部（%d/%d）です。この部分の要点だけを日本語の箇条書きで簡潔に抽出してください:\n\n%s",
                    $i + 1,
                    count($chunks),
                    $chunk,
                ),
                maxTokens: 1500,
            );
            $partials[] = $part['content'];
            $inputTokens += $part['input_tokens'];
            $outputTokens += $part['output_tokens'];
        }

        // reduce: 部分要点を統合
        $final = $this->anthropic->complete(
            self::SYSTEM.self::FORMAT,
            "次は 1 本の動画を分割して抽出した各部分の要点です。全体を通した要約にまとめてください:\n\n".implode("\n\n---\n\n", $partials),
        );
        $inputTokens += $final['input_tokens'];
        $outputTokens += $final['output_tokens'];

        return $this->result($final['content'], $inputTokens, $outputTokens);
    }

    /**
     * @return array{content: string, input_tokens: int, output_tokens: int, prompt_version: string}
     */
    private function result(string $content, int $inputTokens, int $outputTokens): array
    {
        return [
            'content' => $content,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'prompt_version' => self::PROMPT_VERSION,
        ];
    }

    /**
     * 本文をおおよそ CHUNK_TARGET_TOKENS 以下のチャンクに分ける。
     *
     * 自動生成字幕は文の区切り記号が無いことが多いので、単語境界で詰めていく。
     * トークン数は「1 トークン ≒ 3 文字」で概算する（小さめに見積もって安全側）。
     *
     * @return list<string>
     */
    private function chunk(string $text): array
    {
        $text = trim($text);

        if ($this->estimateTokens($text) <= self::CHUNK_TARGET_TOKENS) {
            return [$text];
        }

        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $chunks = [];
        $current = '';
        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;

            if ($current !== '' && $this->estimateTokens($candidate) > self::CHUNK_TARGET_TOKENS) {
                $chunks[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 3);
    }
}
