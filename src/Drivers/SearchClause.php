<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\ValueType;

final readonly class SearchClause
{
    /** @param string|int|float|bool|list<float> $value */
    public function __construct(public string $field, public string $target, public string|int|float|bool|array $value, public MatchType $matchType, public ValueType $valueType) {}
}
