<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

final readonly class PublicChannel
{
    public function __construct(public string $name, public string $projection) {}
}
