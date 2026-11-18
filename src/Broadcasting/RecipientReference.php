<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

final readonly class RecipientReference
{
    /** @internal Package-created encrypted server reference. */
    public function __construct(public string $encrypted) {}
}
