<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use ArtisanToolbox\Dapter\Contracts\PublicRowProjection;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;

/** @internal */
final readonly class LiveRead
{
    /** @param array{type:string,name:string,event:string} $channel
     * @param array<string,mixed> $ticket */
    public function __construct(public RestoredSource $restored, public ResolvedStoreOptions $options, public array $channel, public array $ticket, public ?PublicRowProjection $projection = null) {}
}
