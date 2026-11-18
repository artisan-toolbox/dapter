<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Paging;

final readonly class CursorBoundary
{
    /** @param non-empty-list<string|int|float|bool> $values */
    public function __construct(public array $values, public bool $forward) {}
}
