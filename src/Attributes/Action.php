<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\ActionMethod;
use ArtisanToolbox\Dapter\Enums\ActionTransport;
use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Action
{
    /** @param array<array-key,mixed> $transports */
    public function __construct(public ?string $id = null, public array $transports = [ActionTransport::Visit], public ActionMethod $method = ActionMethod::Post) {}
}
