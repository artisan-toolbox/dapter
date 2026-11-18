<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\OffsetRequest;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;

final readonly class ValidatedReadPlan
{
    public function __construct(public ResolvedStoreOptions $options, public OffsetRequest $paging, public ReadSelection $selection = new ReadSelection, public ?Comparisons\ResolvedComparisons $comparisons = null) {}
}
