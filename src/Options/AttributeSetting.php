<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\OptionKey;

/** @internal */
interface AttributeSetting
{
    public function key(): OptionKey;

    public function value(): mixed;
}
