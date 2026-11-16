<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Options\AttributeSetting;
use ArtisanToolbox\Dapter\Options\GateCheck;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Authorize implements AttributeSetting
{
    public function __construct(public string $ability, public mixed $arguments = []) {}

    public function key(): OptionKey
    {
        return OptionKey::Authorization;
    }

    public function value(): mixed
    {
        return new GateCheck($this->ability, $this->arguments);
    }
}
