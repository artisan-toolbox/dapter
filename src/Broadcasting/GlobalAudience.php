<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

final readonly class GlobalAudience
{
    public function __construct(public string $projection) {}
}
