<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter;

use ArtisanToolbox\Dapter\Console\Commands\MakeDataSource;
use Illuminate\Support\ServiceProvider;

class DapterServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Dapter::class);
        $this->mergeConfigFrom(__DIR__.'/../config/dapter.php', 'dapter');
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([MakeDataSource::class]);
        $this->publishes([__DIR__.'/../config/dapter.php' => config_path('dapter.php')], 'dapter-config');

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/dapter'),
        ], ['dapter', 'dapter-assets']);
    }
}
