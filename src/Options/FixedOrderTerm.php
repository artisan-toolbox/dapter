<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;

final readonly class FixedOrderTerm
{
    public function __construct(public string $field, public SortDirection $direction = SortDirection::Asc, public SortType $sortType = SortType::Native) {}
}
