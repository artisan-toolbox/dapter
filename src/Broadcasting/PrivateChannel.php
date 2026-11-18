<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

final readonly class PrivateChannel
{
    public function __construct(public string $pattern, public string $context, public ?string $authorize = null) {}
}
