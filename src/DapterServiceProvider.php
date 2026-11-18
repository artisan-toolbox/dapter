<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter;

use ArtisanToolbox\Dapter\Console\Commands\MakeDataSource;
use ArtisanToolbox\Dapter\Drivers\DriverRegistry;
use ArtisanToolbox\Dapter\Http\ActionInput;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Support\ServiceProvider;

class DapterServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Dapter::class);
        $this->app->singleton(DriverRegistry::class);
        $this->app->singleton(SourceRegistry::class);
        $this->mergeConfigFrom(__DIR__.'/../config/dapter.php', 'dapter');
    }

    public function boot(): void
    {
        $this->callAfterResolving(HttpKernel::class, static fn (Kernel $kernel) => $kernel->prependMiddleware(ActionInput::class));
        $this->app->booted(fn () => $this->app->make(SourceRegistry::class)->seal());
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
