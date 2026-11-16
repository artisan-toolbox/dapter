<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Options\AttributeSetting;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class PageSizeOptions implements AttributeSetting
{
    /** @param list<int> $sizes */
    public function __construct(public array $sizes) {}

    public function key(): OptionKey
    {
        return OptionKey::Sizes;
    }

    public function value(): mixed
    {
        return $this->sizes;
    }
}
