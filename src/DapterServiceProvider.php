<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter;

use Illuminate\Support\ServiceProvider;

class DapterServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Dapter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/dapter'),
        ], ['dapter', 'dapter-assets']);
    }
}
