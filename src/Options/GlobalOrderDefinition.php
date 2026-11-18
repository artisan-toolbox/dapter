<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use Closure;

final readonly class GlobalOrderDefinition
{
    public function __construct(public string $label, public Closure|string $apply, public ?HandlerOptions $options = null) {}
}
