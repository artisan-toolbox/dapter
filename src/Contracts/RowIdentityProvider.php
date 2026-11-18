<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

use ArtisanToolbox\Dapter\Drivers\SqlIdentitySelector;

interface RowIdentityProvider
{
    public function identify(mixed $rawRow): int|string;

    public function selector(): ?SqlIdentitySelector;

    public function lookup(int|string $publicIdentifier): int|string|null;
}
