<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\SearchMatch;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SearchGrouping
{
    /** @param list<SearchMatch> $allowed */
    public function __construct(public SearchMatch $default = SearchMatch::All, public array $allowed = [SearchMatch::All]) {}
}
