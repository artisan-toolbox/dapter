<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\EmptySelectionPolicy;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\HandlerOptions;
use Attribute;
use Closure;
use UnitEnum;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class GlobalFilter
{
    /** @param array<array-key,mixed>|string|UnitEnum $values */
    public function __construct(public array|string|UnitEnum $values, public ?string $id = null, public Closure|string|null $apply = null, public bool $multiple = false, public EmptySelectionPolicy $empty = EmptySelectionPolicy::Clear, public ?ValueType $valueType = null, public ?HandlerOptions $options = null) {}
}
