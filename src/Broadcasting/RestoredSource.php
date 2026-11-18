<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use ArtisanToolbox\Dapter\Contracts\ChannelContextResolver;
use ArtisanToolbox\Dapter\Contracts\DataSource;
use Illuminate\Http\Request;

final readonly class RestoredSource
{
    public function __construct(public Request $request, public DataSource $source, public ?ChannelContextResolver $audience = null) {}
}
