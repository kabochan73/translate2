<?php

namespace App\Providers;

use App\Services\YouTubeService;
use Illuminate\Support\ServiceProvider;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
