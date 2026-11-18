<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

final readonly class ActionPolicy
{
    public function __construct(public string $ability, public mixed $arguments = [], public ?RecordTarget $target = null) {}
}
