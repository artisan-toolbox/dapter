<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use Closure;

final readonly class QueryOptions
{
    /** @param list<FixedOrderTerm> $fixedOrder */
    public function __construct(public ?ResultIdentity $identity = null, public array $fixedOrder = [], public Closure|string|null $counts = null) {}
}
