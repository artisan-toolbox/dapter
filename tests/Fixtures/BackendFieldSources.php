<?php

declare(strict_types=1);

namespace App\DataSources;

use ArtisanToolbox\Dapter\Attributes\FilterableField;
use ArtisanToolbox\Dapter\Attributes\OrderableField;
use ArtisanToolbox\Dapter\Attributes\SearchableField;
use ArtisanToolbox\Dapter\Attributes\WithoutFieldFilter;
use ArtisanToolbox\Dapter\Attributes\WithoutFieldOrder;
use ArtisanToolbox\Dapter\Attributes\WithoutFieldSearch;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\ValueType;

#[SearchableField('name', [MatchType::Contains, MatchType::Equals])]
#[OrderableField('score', valueType: ValueType::Integer)]
#[FilterableField('score', 'score', [0, 1, null])]
class InheritedBackendFields extends CollectionDataSource
{
    public function source(): array
    {
        return [['name' => 'A', 'score' => 0], ['name' => 'B', 'score' => null]];
    }
}

#[WithoutFieldSearch('name')]
#[WithoutFieldOrder('score')]
#[WithoutFieldFilter('score', 'score')]
class RemovedBackendFields extends InheritedBackendFields {}

#[SearchableField('name')]
#[WithoutFieldSearch('name')]
class CollidingBackendFields extends InheritedBackendFields {}

#[SearchableField('name', [MatchType::Equals])]
class ReplacedBackendFields extends InheritedBackendFields {}
