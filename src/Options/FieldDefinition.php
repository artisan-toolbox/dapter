<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

final readonly class FieldDefinition
{
    /** @param array<string, FilterDefinition> $filters */
    public function __construct(public string $target, public ?SearchDefinition $search = null, public ?OrderDefinition $order = null, public array $filters = [], public ?string $output = null) {}
}
