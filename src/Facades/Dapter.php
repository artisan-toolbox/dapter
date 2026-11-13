<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \ArtisanToolbox\Dapter\Dapter
 */
class Dapter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \ArtisanToolbox\Dapter\Dapter::class;
    }
}
