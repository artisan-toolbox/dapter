<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Enums\PagingMode;

final readonly class DriverCapabilities
{
    public function __construct(public bool $offset = true) {}

    public function supports(PagingMode $mode): bool
    {
        return match ($mode) {
            PagingMode::Offset => $this->offset,
        };
    }
}
