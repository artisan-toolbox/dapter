<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use Illuminate\Support\Collection;

final readonly class CollectionStoreDriver implements StoreDriver
{
    /** @param Collection<array-key, mixed> $rows */
    public function __construct(private Collection $rows) {}

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(offset: true);
    }

    public function execute(ValidatedReadPlan $plan): RawPage
    {
        $total = $this->rows->count();
        $pages = max(1, (int) ceil($total / $plan->paging->perPage));
        $current = min($plan->paging->current, $pages);
        $rows = $this->rows->slice(($current - 1) * $plan->paging->perPage, $plan->paging->perPage)->values()->all();

        return new RawPage(array_values($rows), new OffsetRequest($current, $plan->paging->perPage), $pages, $total);
    }
}
