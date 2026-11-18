<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Options\RecordTarget;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ActionAuthorize
{
    public function __construct(public string $ability, public mixed $arguments = [], public ?RecordTarget $target = null, public ?string $id = null) {}
}
