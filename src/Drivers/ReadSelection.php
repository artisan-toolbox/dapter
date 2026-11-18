<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use stdClass;

final readonly class ReadSelection
{
    /**
     * @param  list<SearchClause>  $searches
     * @param  list<FilterClause>  $filters
     * @param  list<OrderTerm>  $order
     * @param  list<SearchClause>  $globalSearches
     * @param  array<string, list<string|int|float|bool|null>>  $globalFilters
     */
    public function __construct(public array $searches = [], public array $filters = [], public array $order = [], public stdClass $fields = new stdClass, public array $globalSearches = [], public ?string $globalSearch = null, public array $globalFilters = [], public ?string $preset = null, public SearchMatch $searchMatch = SearchMatch::All) {}

    public function requestedGlobal(ResolvedStoreOptions $options): stdClass
    {
        $global = new stdClass;

        if ($this->globalSearch !== null) {
            $global->search = ['value' => $this->globalSearch];
        }

        if ($this->globalFilters !== []) {
            $global->filters = new stdClass;
            foreach ($this->globalFilters as $id => $values) {
                $global->filters->{$id} = ['value' => $options->global->filters[$id]->multiple ? $values : $values[0]];
            }
        }

        return $global;
    }
}
