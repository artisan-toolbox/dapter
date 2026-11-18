<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\SortType;

final readonly class DriverCapabilities
{
    /**
     * @param  list<SortType>  $sortTypes
     * @param  class-string<DataSource>|null  $sourceType
     */
    public function __construct(public bool $offset = true, public array $sortTypes = [SortType::Native], public bool $fieldOperations = false, public ?string $sourceType = null, public bool $composedOperations = false, public bool $simple = false, public bool $cursor = false, public ?Comparisons\ComparisonPolicy $comparisons = null, public bool $literalSearchPolicy = false) {}

    public function supports(PagingMode $mode): bool
    {
        return match ($mode) {
            PagingMode::Offset => $this->offset,
            PagingMode::Simple => $this->simple,
            PagingMode::Cursor => $this->cursor,
        };
    }
}
