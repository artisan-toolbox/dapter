<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Tests;

use ArtisanToolbox\Dapter\DapterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            DapterServiceProvider::class,
        ];
    }
}
