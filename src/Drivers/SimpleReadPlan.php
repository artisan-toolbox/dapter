<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\SimpleRequest;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;

final readonly class SimpleReadPlan
{
    public function __construct(public ResolvedStoreOptions $options, public SimpleRequest $paging, public ReadSelection $selection, public ?Comparisons\ResolvedComparisons $comparisons = null) {}
}
