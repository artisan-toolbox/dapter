<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Options\QueryOptions;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Query
{
    public function __construct(public QueryOptions $options) {}
}
