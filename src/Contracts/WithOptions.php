<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

use ArtisanToolbox\Dapter\StoreOptions;

interface WithOptions
{
    public function options(): StoreOptions;
}
