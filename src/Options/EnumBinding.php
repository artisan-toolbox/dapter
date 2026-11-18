<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use UnitEnum;

final readonly class EnumBinding
{
    /** @param class-string<UnitEnum> $enum */
    public function __construct(public string $enum, public bool $elements = false) {}
}
