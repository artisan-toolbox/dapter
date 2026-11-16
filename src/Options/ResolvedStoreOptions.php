<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\PagingMode;

final readonly class ResolvedStoreOptions
{
    /** @param list<int> $sizes */
    public function __construct(public PagingMode $mode, public int $size, public array $sizes, public string $guard, public AccessMode $access, public ?GateCheck $authorization) {}
}
