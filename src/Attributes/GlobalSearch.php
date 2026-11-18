<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Options\SearchTarget;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class GlobalSearch
{
    /** @param array<string,SearchTarget> $targets */
    public function __construct(public array $targets) {}
}
