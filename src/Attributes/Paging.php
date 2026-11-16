<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Options\AttributeSetting;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Paging implements AttributeSetting
{
    public function __construct(public PagingMode $mode) {}

    public function key(): OptionKey
    {
        return OptionKey::Paging;
    }

    public function value(): mixed
    {
        return $this->mode;
    }
}
