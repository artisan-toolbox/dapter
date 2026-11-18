<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\EmptySelectionPolicy;
use ArtisanToolbox\Dapter\Enums\ValueType;
use Closure;

final readonly class GlobalFilterDefinition
{
    /** @param list<string|int|float|bool|null> $values */
    public function __construct(public array $values, public Closure|string $apply, public bool $multiple = false, public EmptySelectionPolicy $empty = EmptySelectionPolicy::Clear, public ?ValueType $valueType = null, public ?HandlerOptions $options = null) {}
}
