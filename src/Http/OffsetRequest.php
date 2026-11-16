<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use ArtisanToolbox\Dapter\Enums\PagingMode;

final readonly class OffsetRequest
{
    public function __construct(public int $current, public int $perPage) {}

    /** @return array{mode: string, current: int, per_page: int} */
    public function toArray(): array
    {
        return ['mode' => PagingMode::Offset->value, 'current' => $this->current, 'per_page' => $this->perPage];
    }
}
