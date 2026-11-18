<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;

final readonly class OrderDefinition
{
    /**
     * @param  list<SortType>  $sortTypes
     * @param  list<SortDirection>  $directions
     */
    public function __construct(public array $sortTypes, public array $directions, public ?string $target, public ?ValueType $valueType, public ?FieldOptions $options = null) {}
}
