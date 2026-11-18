<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Options\HandlerOptions;
use Attribute;
use Closure;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class GlobalOrder
{
    public function __construct(public ?string $id = null, public ?string $label = null, public Closure|string|null $apply = null, public ?HandlerOptions $options = null) {}
}
