<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\CursorRequest;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use ArtisanToolbox\Dapter\Paging\CursorAccess;

final readonly class CursorReadPlan
{
    public function __construct(public ResolvedStoreOptions $options, public CursorRequest $paging, public ReadSelection $selection, public CursorAccess $cursorAccess, public ?Comparisons\ResolvedComparisons $comparisons = null) {}
}
