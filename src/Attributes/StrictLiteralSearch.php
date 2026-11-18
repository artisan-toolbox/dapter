<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Options\AttributeSetting;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class StrictLiteralSearch implements AttributeSetting
{
    public function __construct(public bool $enabled = true) {}

    public function key(): OptionKey
    {
        return OptionKey::StrictLiteralSearch;
    }

    public function value(): mixed
    {
        return $this->enabled;
    }
}
