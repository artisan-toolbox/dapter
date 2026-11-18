<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\StoreDriver;

/** @internal */
final readonly class ProfiledDriver
{
    public function __construct(public StoreDriver $driver, public DriverCapabilities $profile) {}
}
