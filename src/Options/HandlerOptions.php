<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

final readonly class HandlerOptions
{
    public function __construct(public ?EnumBinding $binding = null, public ?HandlerRequirements $requires = null) {}
}
