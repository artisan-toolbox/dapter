<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\DapterServiceProvider;
use Illuminate\Foundation\Application;

return Application::configure(dirname(__DIR__))
    ->withProviders([DapterServiceProvider::class], withBootstrapProviders: false)
    ->create();
