<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

final readonly class ExactCounts
{
    public function __construct(public int $total, public int $filtered) {}
}
