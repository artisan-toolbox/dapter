<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Actions
{
    public function __construct(public string $actionsClass) {}
}
