<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

use ArtisanToolbox\Dapter\Broadcasting\ChannelReader;
use ArtisanToolbox\Dapter\Broadcasting\GlobalAudience;
use ArtisanToolbox\Dapter\Broadcasting\ScopedAudience;

interface ChannelContextResolver
{
    public function resolve(ChannelReader $reader): GlobalAudience|ScopedAudience|null;
}
