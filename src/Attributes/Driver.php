<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Options\AttributeSetting;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Driver implements AttributeSetting
{
    public function __construct(public string $alias) {}

    public function key(): OptionKey
    {
        return OptionKey::Driver;
    }

    public function value(): mixed
    {
        return $this->alias;
    }
}
