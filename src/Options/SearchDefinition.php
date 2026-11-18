<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\ValueType;

final readonly class SearchDefinition
{
    /** @param list<MatchType> $matchTypes */
    public function __construct(public array $matchTypes, public ?string $target, public ValueType $valueType, public ?FieldOptions $options = null) {}
}
