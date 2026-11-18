<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use Closure;
use ReflectionFunctionAbstract;

/** @internal */
final readonly class TrustedHandler
{
    /** @param Closure|class-string $handler
     * @param  class-string|null  $owner
     */
    public function __construct(public Closure|string $handler, public ReflectionFunctionAbstract $reflection, public ?string $owner = null) {}
}
