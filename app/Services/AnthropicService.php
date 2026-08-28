<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Claude Messages API の薄い HTTP ラッパー（design.md §4.3）。
 *
 * 公式 SDK ではなく Laravel の Http:: を使う（YouTubeService と揃える／
 * Http::fake() でテストする方針 NFR-8）。呼び出し元は SummaryGenerator。
 */
class AnthropicService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
    ) {}

    /**
     * 単発（1 ターン）のメッセージを送り、本文と使用トークン数を返す。
     *
     * system は map-reduce の各呼び出しで同じなので prompt caching を効かせる。
     *
     * @return array{content: string, input_tokens: int, output_tokens: int}
     */
    public function complete(string $system, string $user, int $maxTokens = 4096): array
    {
        $response = $this->request()
            ->post(self::API_URL, [
                'model' => $this->model,
                'max_tokens' => $maxTokens,
                'system' => [[
                    'type' => 'text',
                    'text' => $system,
                    'cache_control' => ['type' => 'ephemeral'],
                ]],
                'messages' => [
                    ['role' => 'user', 'content' => $user],
                ],
            ])
            ->throw();

        $text = $response->json('content.0.text');

        if (! is_string($text) || $text === '') {
            throw new RuntimeException('Anthropic API がテキストを返しませんでした。');
        }

        return [
            'content' => $text,
            // キャッシュ読み書き分も含めた入力トークンの合算（概算コスト用）。
            'input_tokens' => (int) $response->json('usage.input_tokens', 0)
                + (int) $response->json('usage.cache_read_input_tokens', 0)
                + (int) $response->json('usage.cache_creation_input_tokens', 0),
            'output_tokens' => (int) $response->json('usage.output_tokens', 0),
        ];
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * 429（レート制限）/ 529（過負荷）は HTTP クライアント側でもリトライする。
     */
    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => self::API_VERSION,
        ])
            ->timeout(120)
            ->retry(3, 1000, function ($exception) {
                return in_array($exception->response?->status(), [429, 529], true);
            }, throw: false);
    }
}
