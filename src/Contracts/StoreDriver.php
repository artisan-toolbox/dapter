<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

use ArtisanToolbox\Dapter\Drivers\DriverCapabilities;
use ArtisanToolbox\Dapter\Drivers\RawPage;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;

interface StoreDriver
{
    public function capabilities(): DriverCapabilities;

    public function execute(ValidatedReadPlan $plan): RawPage;
}
