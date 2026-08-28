<?php

namespace Tests\Feature\Services;

use App\Services\AnthropicService;
use App\Services\SummaryGenerator;
use Mockery;
use Tests\TestCase;

class SummaryGeneratorTest extends TestCase
{
    /**
     * @param  array<int, array{content: string, input_tokens: int, output_tokens: int}>  $responses
     */
    private function generatorReturning(array $responses, ?int $expectedCalls = null): SummaryGenerator
    {
        $anthropic = Mockery::mock(AnthropicService::class);
        $expectation = $anthropic->allows('complete')->andReturnValues($responses);

        if ($expectedCalls !== null) {
            $expectation->times($expectedCalls);
        }

        return new SummaryGenerator($anthropic);
    }

    public function test_short_transcript_uses_a_single_call(): void
    {
        $generator = $this->generatorReturning([
            ['content' => '## TL;DR\n短い要約', 'input_tokens' => 800, 'output_tokens' => 300],
        ], expectedCalls: 1);

        $result = $generator->generate('This is a short transcript.');

        $this->assertStringContainsString('短い要約', $result['content']);
        $this->assertSame(800, $result['input_tokens']);
        $this->assertSame(300, $result['output_tokens']);
        $this->assertSame('v1', $result['prompt_version']);
    }

    public function test_long_transcript_maps_each_chunk_then_reduces(): void
    {
        // 24k 文字 ≒ 8k トークン（1tok≒3字）→ 6k 目安で 2 チャンク → map 2 + reduce 1 = 3 呼び出し
        $longText = trim(str_repeat('word ', 4800));

        $generator = $this->generatorReturning([
            ['content' => '- 部分1の要点', 'input_tokens' => 5000, 'output_tokens' => 200],
            ['content' => '- 部分2の要点', 'input_tokens' => 5000, 'output_tokens' => 200],
            ['content' => '## TL;DR\n統合要約', 'input_tokens' => 600, 'output_tokens' => 400],
        ], expectedCalls: 3);

        $result = $generator->generate($longText);

        $this->assertStringContainsString('統合要約', $result['content']);
        $this->assertSame(10600, $result['input_tokens']); // 5000 + 5000 + 600
        $this->assertSame(800, $result['output_tokens']);   // 200 + 200 + 400
    }
}
