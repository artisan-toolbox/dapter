<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Broadcasting\PrivateChannel;
use ArtisanToolbox\Dapter\Broadcasting\PublicChannel;

/** @internal */
final readonly class BroadcastOptions
{
    public function __construct(public ?string $identifierField = null, public PrivateChannel|PublicChannel|null $channel = null, public ?string $revisions = null, public ?string $identity = null) {}
}
