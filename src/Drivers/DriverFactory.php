<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\CollectionSource;
use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\EloquentSource;
use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Http\DapterException;
use Illuminate\Support\Collection;

final class DriverFactory
{
    public function make(DataSource $source): StoreDriver
    {
        if ($source instanceof CollectionSource) {
            return new CollectionStoreDriver(new Collection($source->source()));
        }

        if ($source instanceof EloquentSource) {
            return new EloquentStoreDriver($source->source());
        }

        throw DapterException::configuration();
    }
}
