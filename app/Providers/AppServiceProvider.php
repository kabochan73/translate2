<?php

namespace App\Providers;

use App\Services\AnthropicService;
use App\Services\YouTubeService;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use Illuminate\Support\ServiceProvider;
use MrMySQL\YoutubeTranscript\TranscriptListFetcher;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(
            YouTubeService::class,
            fn () => new YouTubeService(config('services.youtube.key')),
        );

        // 字幕取得ライブラリ。PSR-18 クライアント + PSR-17 ファクトリを渡す
        // （Laravel が Guzzle を同梱しているのでそれを使う）。
        $this->app->singleton(TranscriptListFetcher::class, function () {
            $factory = new HttpFactory;

            return new TranscriptListFetcher(new GuzzleClient, $factory, $factory);
        });

        $this->app->singleton(AnthropicService::class, fn () => new AnthropicService(
            config('services.anthropic.key'),
            config('services.anthropic.model'),
            config('services.anthropic.workspace_id'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
