<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\SimpleRequest;

final readonly class SimplePage
{
    /**
     * @param  list<mixed>  $rows
     * @param  array<string,mixed>|null  $effectiveOrder
     */
    public function __construct(public array $rows, public SimpleRequest $paging, public bool $hasPrevious, public bool $hasNext, public ?ExactCounts $counts, public ?array $effectiveOrder = null) {}
}
