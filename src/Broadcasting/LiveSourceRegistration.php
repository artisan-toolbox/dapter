<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\DeliveryContextFactory;
use ArtisanToolbox\Dapter\Contracts\PublicRowProjection;
use ArtisanToolbox\Dapter\StoreOptions;

final readonly class LiveSourceRegistration
{
    /**
     * @param  class-string<DataSource>  $source
     * @param  class-string<DeliveryContextFactory>  $contexts
     * @param  class-string<PublicRowProjection>|null  $publicProjection
     */
    public function __construct(public string $source, public StoreOptions $options, public string $contexts, public string $generation, public ?string $publicProjection = null, public ?string $producerAuthorize = null) {}
}
