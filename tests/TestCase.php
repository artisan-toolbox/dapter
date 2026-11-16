<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Tests;

use ArtisanToolbox\Dapter\DapterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    protected function getPackageProviders($app): array
    {
        return [
            DapterServiceProvider::class,
        ];
    }
}
