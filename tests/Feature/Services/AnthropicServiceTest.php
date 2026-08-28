<?php

namespace Tests\Feature\Services;

use App\Services\AnthropicService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class AnthropicServiceTest extends TestCase
{
    private function service(): AnthropicService
    {
        return new AnthropicService('test-key', 'claude-sonnet-5');
    }

    public function test_complete_returns_the_text_and_token_usage(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => '要約です。']],
                'usage' => [
                    'input_tokens' => 1000,
                    'output_tokens' => 200,
                    'cache_read_input_tokens' => 500,
                    'cache_creation_input_tokens' => 50,
                ],
            ]),
        ]);

        $result = $this->service()->complete('あなたは要約者です', '長い字幕...');

        $this->assertSame('要約です。', $result['content']);
        $this->assertSame(1550, $result['input_tokens']); // 1000 + 500 + 50
        $this->assertSame(200, $result['output_tokens']);
    }

    public function test_it_sends_the_model_headers_and_cached_system_prompt(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
        ]);

        $this->service()->complete('SYS', 'USER', maxTokens: 1234);

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://api.anthropic.com/v1/messages'
                && $request->hasHeader('x-api-key', 'test-key')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['model'] === 'claude-sonnet-5'
                && $request['max_tokens'] === 1234
                && $request['system'][0]['text'] === 'SYS'
                && $request['system'][0]['cache_control']['type'] === 'ephemeral'
                && $request['messages'][0] === ['role' => 'user', 'content' => 'USER'];
        });
    }

    public function test_it_sends_the_workspace_id_header_when_configured(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
        ]);

        (new AnthropicService('test-key', 'claude-sonnet-5', 'wrkspc_123'))->complete('SYS', 'USER');

        Http::assertSent(fn (Request $request) => $request->hasHeader('anthropic-workspace-id', 'wrkspc_123'));
    }

    public function test_it_omits_the_workspace_id_header_when_not_configured(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'ok']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
        ]);

        $this->service()->complete('SYS', 'USER');

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('anthropic-workspace-id'));
    }

    public function test_it_retries_on_529_then_succeeds(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['type' => 'error'], 529)
                ->push([
                    'content' => [['type' => 'text', 'text' => 'done']],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200),
        ]);

        $result = $this->service()->complete('SYS', 'USER');

        $this->assertSame('done', $result['content']);
        Http::assertSentCount(2);
    }

    public function test_it_throws_when_the_response_has_no_text(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
            ]),
        ]);

        $this->expectException(RuntimeException::class);

        $this->service()->complete('SYS', 'USER');
    }
}
