<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

final readonly class GateCheck
{
    public function __construct(public string $ability, public mixed $arguments = []) {}
}
