<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\OffsetRequest;

final readonly class RawPage
{
    /** @param list<mixed> $rows */
    public function __construct(public array $rows, public OffsetRequest $paging, public int $pages, public int $total) {}
}
