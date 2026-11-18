<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

final readonly class ScopedAudience
{
    public function __construct(public string $scope, public string $projection) {}
}
