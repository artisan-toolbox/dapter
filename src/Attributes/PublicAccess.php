<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Options\AttributeSetting;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class PublicAccess implements AttributeSetting
{
    public function __construct(public bool $enabled = true) {}

    public function key(): OptionKey
    {
        return OptionKey::Access;
    }

    public function value(): mixed
    {
        return $this->enabled ? AccessMode::Public : AccessMode::Required;
    }
}
