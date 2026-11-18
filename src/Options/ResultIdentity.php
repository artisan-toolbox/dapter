<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

final readonly class ResultIdentity
{
    /** @param list<IdentityColumn> $columns */
    public function __construct(public array $columns) {}
}
