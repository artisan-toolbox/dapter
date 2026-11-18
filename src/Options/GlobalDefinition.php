<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

final readonly class GlobalDefinition
{
    /** @param array<string,SearchTarget>|null $search
     * @param array<string,GlobalFilterDefinition> $filters
     * @param array<string,GlobalOrderDefinition> $orders */
    public function __construct(public ?array $search = null, public array $filters = [], public array $orders = []) {}
}
