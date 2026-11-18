<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Contracts\TraversalStoreDriver;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Http\CursorRequest;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use ArtisanToolbox\Dapter\Http\SimpleRequest;
use ArtisanToolbox\Dapter\Options\FixedOrderTerm;
use ArtisanToolbox\Dapter\Options\GlobalOrderDefinition;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use ArtisanToolbox\Dapter\Paging\CursorAccess;
use stdClass;

/** @internal */
final readonly class NegotiatedRead
{
    private function __construct(
        private StoreDriver $driver,
        private ValidatedReadPlan|SimpleReadPlan|CursorReadPlan $plan,
        private int $version = 1,
    ) {}

    public static function fromDriver(StoreDriver $driver, ResolvedStoreOptions $options, OffsetRequest|SimpleRequest|CursorRequest $paging, ?ReadSelection $selection = null, int $version = 1, ?CursorAccess $cursorAccess = null, ?DriverCapabilities $profile = null): self
    {
        if ($driver instanceof EloquentStoreDriver && $profile === null) {
            $driver = $driver->withQueryOptions($options->query);
        }
        $capabilities = $profile ?? $driver->capabilities();

        if (! $capabilities->supports($options->mode)) {
            throw DapterException::configuration();
        }

        if (! $capabilities->composedOperations && ($options->global?->search !== null || ($options->global->filters ?? []) !== [] || ($options->global->orders ?? []) !== [] || $options->multiOrder || $options->query !== null || $options->allowedSearchMatches !== [SearchMatch::All])) {
            throw DapterException::configuration();
        }
        $selection ??= new ReadSelection;
        $comparisons = ($capabilities->comparisons ?? Comparisons\ComparisonPolicy::legacy($capabilities->sortTypes))->resolve($options, $selection, $capabilities->literalSearchPolicy);
        $options = $comparisons->options;
        foreach ($options->fields as $field) {
            if (! $capabilities->fieldOperations) {
                throw DapterException::configuration();
            }
            foreach ($field->order->sortTypes ?? [] as $sortType) {
                if ($sortType !== SortType::Locale && ! in_array($sortType, $capabilities->sortTypes, true)) {
                    throw DapterException::configuration();
                }
            }
        }

        if (! $paging instanceof OffsetRequest && (! $driver instanceof TraversalStoreDriver || $version !== 2)) {
            throw DapterException::configuration();
        }
        $plan = match (true) {
            $paging instanceof OffsetRequest => new ValidatedReadPlan($options, $paging, $selection, $comparisons),
            $paging instanceof SimpleRequest => new SimpleReadPlan($options, $paging, $selection, $comparisons),
            default => new CursorReadPlan($options, $paging, $selection, $cursorAccess ?? throw DapterException::configuration(), $comparisons),
        };

        return new self($driver, $plan, $version);
    }

    public function execute(): RawPage|SimplePage|CursorPage
    {
        if ($this->plan instanceof ValidatedReadPlan) {
            return $this->driver->execute($this->plan);
        }

        if (! $this->driver instanceof TraversalStoreDriver) {
            throw DapterException::configuration();
        }

        return $this->driver->traverse($this->plan);
    }

    /** @return array<string, mixed> */
    public function wireCapabilities(): array
    {
        $fields = new stdClass;

        if ($this->version === 2) {
            foreach ($this->plan->options->fields as $name => $field) {
                $filters = new stdClass;
                foreach ($field->filters as $id => $filter) {
                    $filters->{$id} = ['values' => $filter->values, 'multiple' => $filter->multiple, ...($filter->multiple ? ['empty' => $filter->empty->value] : [])];
                }
                $fields->{$name} = [
                    'search' => $field->search === null ? null : ['matchTypes' => array_map(static fn (MatchType $type): string => $type->value, $field->search->matchTypes), 'valueType' => $field->search->valueType->value, ...($this->plan->comparisons?->searchMetadata($name) ?? [])],
                    'order' => $field->order === null ? null : ['sortTypes' => array_map(static fn (SortType $type): string => $type->value, $field->order->sortTypes), 'directions' => array_map(static fn (SortDirection $direction): string => $direction->value, $field->order->directions), ...($this->plan->comparisons?->orderMetadata($name) ?? [])],
                    'filters' => get_object_vars($filters) === [] ? null : $filters,
                ];
            }
        }

        $options = $this->plan->options;
        $globalFilters = new stdClass;
        foreach ($options->global->filters ?? [] as $id => $filter) {
            $globalFilters->{$id} = ['values' => $filter->values, 'multiple' => $filter->multiple, ...($filter->multiple ? ['empty' => $filter->empty->value] : [])];
        }
        $targets = new stdClass;
        foreach ($options->global->search ?? [] as $id => $target) {
            $targets->{$id} = ['valueType' => $target->valueType->value, 'matchType' => $target->matchType->value, ...($this->plan->comparisons?->searchMetadata($id, true) ?? [])];
        }
        $global = ['multi_order' => $options->multiOrder, 'multi_order_limit' => $options->multiOrder ? $options->maxOrder : 1, 'searchMatch' => ['default' => $options->searchMatch->value, 'allowed' => array_map(static fn (SearchMatch $match): string => $match->value, $options->allowedSearchMatches)], 'filters' => $options->global?->filters ? $globalFilters : null, 'search' => $options->global?->search ? ['valueType' => 'string', 'targets' => $targets] : null, 'order' => $options->global?->orders ? ['presets' => array_map(static fn (string $id, GlobalOrderDefinition $preset): array => ['id' => $id, 'label' => $preset->label], array_keys($options->global->orders), array_values($options->global->orders)), 'pagingModes' => ['offset']] : null];

        if ($this->version === 1) {
            $global = ['multi_order' => false, 'multi_order_limit' => 1, 'searchMatch' => ['default' => 'all', 'allowed' => ['all']], 'filters' => null, 'search' => null, 'order' => null];
        }

        if ($this->version === 2 && $options->query !== null) {
            $global['stability'] = ['prefix' => array_map(static fn (FixedOrderTerm $term): array => ['id' => $term->field, 'direction' => $term->direction->value, 'sortType' => $term->sortType->value], $options->query->fixedOrder), 'identity' => array_map(static fn (IdentityColumn $column): array => ['id' => $column->output, 'direction' => 'asc', 'sortType' => 'native'], $options->query->identity->columns ?? [])];
        }

        return [
            'fields' => $fields,
            'global' => $global,
            'paging' => ['mode' => $this->plan->options->mode->value, 'per_page' => $this->plan->options->size, 'per_page_options' => $this->plan->options->sizes, ...($this->plan instanceof ValidatedReadPlan ? [] : ['counts' => $options->exactCounts ? 'exact' : 'unknown']), ...($this->plan instanceof CursorReadPlan ? ['expires_after' => CursorAccess::LIFETIME] : [])],
        ];
    }
}
