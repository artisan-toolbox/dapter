<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Enums\ValueType;

final readonly class FilterClause
{
    /** @param list<string|int|float|bool|null> $values */
    public function __construct(public string $field, public string $id, public string $target, public array $values, public ?ValueType $valueType) {}
}
