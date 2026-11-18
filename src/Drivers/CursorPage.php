<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\CursorRequest;

final readonly class CursorPage
{
    /**
     * @param  list<mixed>  $rows
     * @param  array<string,mixed>|null  $effectiveOrder
     */
    public function __construct(public array $rows, public CursorRequest $paging, public ?string $previousCursor, public ?string $nextCursor, public ?ExactCounts $counts, public ?array $effectiveOrder = null) {}
}
