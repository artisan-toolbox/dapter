<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\ValueType;

final readonly class RecordTarget
{
    public function __construct(public string $input, public ValueType $valueType = ValueType::String, public ?string $target = null, public ?string $output = null) {}
}
