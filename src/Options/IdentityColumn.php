<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\ValueType;

final readonly class IdentityColumn
{
    public function __construct(public string $target, public string $output, public ValueType $valueType) {}
}
