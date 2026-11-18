<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Paging;

use ArtisanToolbox\Dapter\Drivers\OrderTerm;

final readonly class CursorExecutionContext
{
    /** @param non-empty-list<OrderTerm> $order */
    public function __construct(public string $baseScopeFingerprint, public string $selectedScopeFingerprint, public array $order) {}
}
