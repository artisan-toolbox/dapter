<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Actions;

use ArtisanToolbox\Dapter\Enums\ActionMethod;
use ArtisanToolbox\Dapter\Enums\ActionTransport;
use ArtisanToolbox\Dapter\Options\ActionPolicy;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionMethod;

/** @internal */
final readonly class ActionDefinition
{
    /** @param list<ActionTransport> $transports
     * @param class-string|null $owner
     * @param class-string<FormRequest>|null $formRequest */
    public function __construct(public string $id, public ActionMethod $method, public array $transports, public ReflectionMethod $handler, public ?string $owner, public ?ActionPolicy $policy, public ?string $formRequest) {}
}
