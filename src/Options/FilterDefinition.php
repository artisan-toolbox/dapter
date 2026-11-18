<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\EmptySelectionPolicy;
use ArtisanToolbox\Dapter\Enums\ValueType;
use UnitEnum;

final readonly class FilterDefinition
{
    /** @param array<array-key,string|int|float|bool|null|UnitEnum>|string|UnitEnum $values */
    public function __construct(public array|string|UnitEnum $values, public bool $multiple, public EmptySelectionPolicy $empty, public ?string $target, public ?ValueType $valueType, public ?FieldOptions $options = null) {}
}
