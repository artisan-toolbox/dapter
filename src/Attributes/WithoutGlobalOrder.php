<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class WithoutGlobalOrder
{
    public function __construct(public string $id) {}
}
