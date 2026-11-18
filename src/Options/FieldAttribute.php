<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

/** @internal */
interface FieldAttribute
{
    public function setting(): FieldSetting;
}
