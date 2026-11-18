<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

use ArtisanToolbox\Dapter\Drivers\CursorPage;
use ArtisanToolbox\Dapter\Drivers\CursorReadPlan;
use ArtisanToolbox\Dapter\Drivers\SimplePage;
use ArtisanToolbox\Dapter\Drivers\SimpleReadPlan;

interface TraversalStoreDriver extends StoreDriver
{
    public function traverse(SimpleReadPlan|CursorReadPlan $plan): SimplePage|CursorPage;
}
