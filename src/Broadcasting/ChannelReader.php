<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class ChannelReader
{
    /** @internal Package-created selected principal. */
    public function __construct(public string $source, public string $guard, public Authenticatable $user) {}
}
