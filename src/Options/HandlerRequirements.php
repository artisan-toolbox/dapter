<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Contracts\DataSource;

final readonly class HandlerRequirements
{
    /** @param class-string<DataSource>|null $sourceType
     * @param list<string> $databases */
    public function __construct(public ?string $sourceType = null, public array $databases = [], public ?string $driver = null) {}
}
