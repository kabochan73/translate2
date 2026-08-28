<?php

namespace Tests\Feature\Services;

use App\Services\TranscriptService;
use Mockery;
use MrMySQL\YoutubeTranscript\Exception\NoTranscriptFoundException;
use MrMySQL\YoutubeTranscript\Exception\TooManyRequestsException;
use MrMySQL\YoutubeTranscript\Exception\TranscriptsDisabledException;
use MrMySQL\YoutubeTranscript\Transcript;
use MrMySQL\YoutubeTranscript\TranscriptList;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;
use Tests\TestCase;

class TranscriptServiceTest extends TestCase
{
    /**
     * @param  array<int, array{text: string, start: float, duration: float}>  $entries
     */
    private function fakeTranscript(string $languageCode, array $entries): Transcript
    {
        $transcript = Mockery::mock(Transcript::class);
        $transcript->language_code = $languageCode;
        $transcript->allows('fetch')->andReturn($entries);

        return $transcript;
    }

    private function serviceReturning(TranscriptList $list): TranscriptService
    {
        $fetcher = Mockery::mock(TranscriptListFetcher::class);
        $fetcher->allows('fetch')->andReturn($list);

        return new TranscriptService($fetcher);
    }

    public function test_it_returns_the_transcript_in_the_preferred_language(): void
    {
        $en = $this->fakeTranscript('en', [
            ['text' => 'hello', 'start' => 0.0, 'duration' => 1.5],
            ['text' => 'world', 'start' => 1.5, 'duration' => 2.0],
        ]);
        $list = Mockery::mock(TranscriptList::class);
        $list->allows('findTranscript')->with(['en'])->andReturn($en);

        $result = $this->serviceReturning($list)->fetch('vid00000001', 'en');

        $this->assertSame('en', $result['language']);
        $this->assertSame('hello world', $result['content']);
        $this->assertSame(
            [['start' => 0.0, 'end' => 1.5, 'text' => 'hello'], ['start' => 1.5, 'end' => 3.5, 'text' => 'world']],
            $result['segments'],
        );
    }

    public function test_it_falls_back_from_the_regional_code_to_the_base_language(): void
    {
        $en = $this->fakeTranscript('en', [['text' => 'hi', 'start' => 0.0, 'duration' => 1.0]]);
        $list = Mockery::mock(TranscriptList::class);
        $list->allows('findTranscript')->with(['en-US', 'en'])->andReturn($en);

        $result = $this->serviceReturning($list)->fetch('vid00000001', 'en-US');

        $this->assertSame('en', $result['language']);
    }

    public function test_it_falls_back_to_any_available_language(): void
    {
        $ja = $this->fakeTranscript('ja', [['text' => 'こんにちは', 'start' => 0.0, 'duration' => 1.0]]);
        $list = Mockery::mock(TranscriptList::class);
        $list->allows('findTranscript')->with(['fr'])->andThrow(new NoTranscriptFoundException);
        $list->allows('getAvailableLanguageCodes')->andReturn(['ja']);
        $list->allows('findTranscript')->with(['ja'])->andReturn($ja);

        $result = $this->serviceReturning($list)->fetch('vid00000001', 'fr');

        $this->assertSame('ja', $result['language']);
    }

    public function test_it_returns_null_when_no_transcript_exists(): void
    {
        $list = Mockery::mock(TranscriptList::class);
        $list->allows('getAvailableLanguageCodes')->andReturn([]);
        $list->allows('findTranscript')->andThrow(new NoTranscriptFoundException);

        $this->assertNull($this->serviceReturning($list)->fetch('vid00000001'));
    }

    public function test_it_returns_null_when_captions_are_disabled(): void
    {
        $list = Mockery::mock(TranscriptList::class);
        $list->allows('getAvailableLanguageCodes')->andReturn(['en']);
        $list->allows('findTranscript')->andThrow(new TranscriptsDisabledException);

        $this->assertNull($this->serviceReturning($list)->fetch('vid00000001'));
    }

    public function test_it_decodes_html_entities_in_the_text(): void
    {
        $en = $this->fakeTranscript('en', [['text' => 'it&#39;s a &quot;test&quot;', 'start' => 0.0, 'duration' => 1.0]]);
        $list = Mockery::mock(TranscriptList::class);
        $list->allows('getAvailableLanguageCodes')->andReturn(['en']);
        $list->allows('findTranscript')->andReturn($en);

        $result = $this->serviceReturning($list)->fetch('vid00000001');

        $this->assertSame('it\'s a "test"', $result['content']);
    }

    public function test_transient_failures_propagate_as_exceptions(): void
    {
        $fetcher = Mockery::mock(TranscriptListFetcher::class);
        $fetcher->allows('fetch')->andThrow(new TooManyRequestsException);

        $this->expectException(TooManyRequestsException::class);

        (new TranscriptService($fetcher))->fetch('vid00000001');
    }
}
