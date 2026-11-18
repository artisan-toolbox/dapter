<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;

final readonly class OrderTerm
{
    public function __construct(public string $field, public string $target, public SortDirection $direction, public SortType $sortType, public ?ValueType $valueType, public ?string $output = null, public ?string $role = null) {}

    /** @return array{field:string,direction:string,sortType:string} */
    public function toArray(): array
    {
        return ['field' => $this->field, 'direction' => $this->direction->value, 'sortType' => $this->sortType->value, ...($this->role === null ? [] : ['role' => $this->role])];
    }
}
