<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class MultiOrder
{
    public function __construct(public bool $enabled = true, public int $max = 1) {}
}
