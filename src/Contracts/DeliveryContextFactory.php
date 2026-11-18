<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

use ArtisanToolbox\Dapter\Broadcasting\ChannelReader;
use ArtisanToolbox\Dapter\Broadcasting\RestoredSource;
use Illuminate\Http\Request;

interface DeliveryContextFactory
{
    public function capture(Request $request): ?string;

    public function restore(string $key, ?ChannelReader $reader): ?RestoredSource;
}
