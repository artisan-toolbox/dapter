<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Http\DapterException;

final readonly class VectorOptions
{
    public function __construct(public int $dimensions, public float $maxDistance)
    {
        if ($dimensions < 1 || $dimensions > 16000 || ! is_finite($maxDistance) || $maxDistance < 0 || $maxDistance > 9007199254740991) {
            throw DapterException::configuration();
        }
    }
}
