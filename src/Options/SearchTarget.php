<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\ValueType;

final readonly class SearchTarget
{
    public function __construct(public string $target, public ValueType $valueType = ValueType::String, public MatchType $matchType = MatchType::Contains, public ?FullTextOptions $fullText = null) {}
}
