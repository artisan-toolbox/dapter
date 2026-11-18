<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\CollectionSource;
use ArtisanToolbox\Dapter\Contracts\IdentityLookupDriver;
use ArtisanToolbox\Dapter\Contracts\RowIdentityProvider;
use ArtisanToolbox\Dapter\Contracts\TraversalStoreDriver;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use ArtisanToolbox\Dapter\Paging\CursorBoundary;
use ArtisanToolbox\Dapter\Paging\CursorExecutionContext;
use ArtisanToolbox\Dapter\Paging\StableFingerprint;
use ArtisanToolbox\Dapter\Support\RowIdentity;
use ArtisanToolbox\Dapter\Support\RowValue;
use ArtisanToolbox\Dapter\Support\ScalarValue;
use ArtisanToolbox\Dapter\Support\TrustedHandlerBinder;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;

final readonly class CollectionStoreDriver implements IdentityLookupDriver, TraversalStoreDriver
{
    /** @param Collection<array-key, mixed> $rows */
    public function __construct(private Collection $rows) {}

    /**
     * @param  list<int|string>  $publicIds
     * @return list<mixed>
     */
    public function identifiedRows(RowIdentityProvider $identity, array $publicIds): array
    {
        $wanted = array_fill_keys(array_map(RowIdentity::key(...), $publicIds), true);
        $rows = [];
        $seen = [];
        foreach ($this->rows as $row) {
            $id = RowIdentity::validate($identity->identify($row));
            $key = RowIdentity::key($id);

            if (isset($wanted[$key])) {
                if (isset($seen[$key])) {
                    throw DapterException::configuration();
                }
                $seen[$key] = true;
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public function capabilities(): DriverCapabilities
    {
        return new DriverCapabilities(offset: true, sortTypes: [SortType::Native, SortType::Regular, SortType::String, SortType::Natural, SortType::Numeric], fieldOperations: true, sourceType: CollectionSource::class, composedOperations: true, simple: true, cursor: true, comparisons: Comparisons\ComparisonPolicy::collection(), literalSearchPolicy: true);
    }

    public function execute(ValidatedReadPlan $plan): RawPage
    {
        [$total, $rows, $terms] = $this->prepare($plan);
        $filtered = count($rows);

        if ($filtered > $total) {
            throw DapterException::configuration();
        }
        $pages = max(1, (int) ceil($filtered / $plan->paging->perPage));
        $current = min($plan->paging->current, $pages);
        $rows = array_slice($rows, ($current - 1) * $plan->paging->perPage, $plan->paging->perPage);

        return new RawPage($rows, new OffsetRequest($current, $plan->paging->perPage), $pages, $total, $filtered, AutomaticOrder::wire($plan, $terms));
    }

    public function traverse(SimpleReadPlan|CursorReadPlan $plan): SimplePage|CursorPage
    {
        if ($plan->selection->preset !== null) {
            throw DapterException::unsupported();
        }
        $boundary = null;
        $context = null;

        if ($plan instanceof CursorReadPlan) {
            if ($plan->options->query?->identity === null) {
                throw DapterException::unsupported();
            }
            $terms = self::cursorTerms($plan);
            $scope = StableFingerprint::of(['collection', 1]);
            $context = new CursorExecutionContext($scope, $scope, $terms);
            $plan->cursorAccess->assertBaseScope($scope);
            $boundary = $plan->cursorAccess->accept($context);
        }
        [$total, $rows, $terms] = $this->prepare($plan);
        $counts = $plan->options->exactCounts ? new ExactCounts($total, count($rows)) : null;

        if ($plan instanceof SimpleReadPlan) {
            $rows = array_slice($rows, ($plan->paging->current - 1) * $plan->paging->perPage, $plan->paging->perPage + 1);

            return new SimplePage(array_slice($rows, 0, $plan->paging->perPage), $plan->paging, $plan->paging->current > 1, count($rows) > $plan->paging->perPage, $counts, AutomaticOrder::wire($plan, $terms));
        }

        if ($terms === []) {
            throw DapterException::unsupported();
        }
        foreach ($rows as $row) {
            self::tuple($row, $terms);
        }
        $forward = $boundary === null ? true : $boundary->forward;

        if ($boundary !== null) {
            $rows = array_values(array_filter($rows, static function (mixed $row) use ($terms, $boundary): bool {
                foreach (self::tuple($row, $terms) as $index => $value) {
                    $comparison = self::compareValue($value, $boundary->values[$index], $terms[$index], true);

                    if ($comparison !== 0) {
                        return ($comparison > 0) === $boundary->forward;
                    }
                }

                return false;
            }));
        }

        if (! $forward) {
            $rows = array_reverse($rows);
        }
        $cursor = $boundary === null ? null : new Cursor($boundary->values, $forward);
        $paginator = new CursorPaginator(array_slice($rows, 0, $plan->paging->perPage + 1), $plan->paging->perPage, $cursor);
        $rows = array_values($paginator->items());
        $previous = $rows !== [] && ! $paginator->onFirstPage() ? $plan->cursorAccess->seal(new CursorBoundary(self::tuple($rows[0], $terms), false), $context) : null;
        $next = $rows !== [] && $paginator->hasMorePages() ? $plan->cursorAccess->seal(new CursorBoundary(self::tuple($rows[array_key_last($rows)], $terms), true), $context) : null;

        return new CursorPage($rows, $plan->paging, $previous, $next, $counts, AutomaticOrder::wire($plan, $terms));
    }

    /** @return non-empty-list<OrderTerm> */
    private static function cursorTerms(CursorReadPlan $plan): array
    {
        $terms = [];
        foreach (AutomaticOrder::forSelection($plan->options, $plan->selection) as $term) {
            if ($term->valueType === null) {
                throw DapterException::unsupported();
            }

            if (! in_array($term->target, array_column($terms, 'target'), true)) {
                $terms[] = $term;
            }
        }

        if ($terms === []) {
            throw DapterException::unsupported();
        }

        return $terms;
    }

    /**
     * @param  non-empty-list<OrderTerm>  $terms
     * @return non-empty-list<string|int|float|bool>
     */
    private static function tuple(mixed $row, array $terms): array
    {
        $values = [];
        foreach ($terms as $term) {
            $value = self::orderValue($row, $term);

            if ($value === null) {
                throw DapterException::unsupported();
            }
            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param  array<array-key,mixed>  $rows
     * @return array<string,int>
     */
    private static function membership(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $token = (is_object($row) ? spl_object_id($row).':' : '').serialize($row);
            $counts[$token] = ($counts[$token] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array{int,list<mixed>,list<OrderTerm>} */
    private function prepare(ValidatedReadPlan|SimpleReadPlan|CursorReadPlan $plan): array
    {
        if (($plan instanceof ValidatedReadPlan || $plan->options->exactCounts) && $plan->options->query?->counts !== null) {
            throw DapterException::configuration();
        }
        $rows = $this->rows->values()->all();
        $this->validateRows($rows, $plan);
        $total = count($rows);
        $authorized = $plan->selection->globalFilters !== [] || $plan->selection->preset !== null
            ? self::membership($rows)
            : null;
        $working = new Collection($rows);
        $binder = new TrustedHandlerBinder;
        foreach ($plan->options->global->filters ?? [] as $definition) {
            $binder->signature($binder->describe($definition->apply), Collection::class, 2);
        }
        foreach ($plan->options->global->orders ?? [] as $definition) {
            $binder->signature($binder->describe($definition->apply), Collection::class, 1);
        }
        $handlers = [];
        foreach ($plan->selection->globalFilters as $id => $values) {
            $definition = $plan->options->global->filters[$id];
            $handler = $binder->describe($definition->apply);
            $handlers[] = [$handler, $binder->bindValue($handler, $definition->multiple ? $values : $values[0], $definition->options)];
        }
        foreach ($handlers as [$handler, $value]) {
            $working = $binder->invoke($handler, [clone $working, $value]);

            if (! $working instanceof Collection) {
                throw DapterException::configuration();
            }
        }

        if ($plan->selection->preset !== null) {
            $membership = self::membership($working->all());
            $working = $binder->invoke($binder->describe($plan->options->global->orders[$plan->selection->preset]->apply), [clone $working]);

            if (! $working instanceof Collection || self::membership($working->all()) != $membership) {
                throw DapterException::configuration();
            }
        }
        $rows = $working->values()->all();

        if ($authorized !== null) {
            foreach (self::membership($rows) as $token => $count) {
                if ($count > ($authorized[$token] ?? 0)) {
                    throw DapterException::configuration();
                }
            }
        }
        $this->validateRows($rows, $plan);
        $rows = array_values(array_filter($rows, static function (mixed $row) use ($plan): bool {
            $matches = [];
            foreach ($plan->selection->searches as $search) {
                $field = $plan->options->fields[$search->field];
                $matches[] = self::matches($row, $search, $plan->comparisons, $field->output);
            }

            if ($plan->selection->globalSearch !== null) {
                $global = false;
                foreach ($plan->selection->globalSearches as $search) {
                    $global = self::matches($row, $search, $plan->comparisons) || $global;
                }
                $matches[] = $global;
            }

            if ($matches !== [] && ($plan->selection->searchMatch === SearchMatch::All ? in_array(false, $matches, true) : ! in_array(true, $matches, true))) {
                return false;
            }
            foreach ($plan->selection->filters as $filter) {
                $field = $plan->options->fields[$filter->field];
                $value = $field->output === null ? RowValue::get($row, $filter->target) : RowValue::output($row, $field->output);

                if (! in_array($filter->valueType === null ? $value : ScalarValue::normalize($value, $filter->valueType, true), $filter->values, true)) {
                    return false;
                }
            }

            return true;
        }));
        $terms = $plan instanceof CursorReadPlan ? self::cursorTerms($plan) : AutomaticOrder::forSelection($plan->options, $plan->selection);

        if (! $plan instanceof CursorReadPlan && $plan->selection->order === []) {
            $terms = array_values(array_filter($terms, static fn (OrderTerm $term): bool => $term->role === 'prefix'));
        }

        if ($plan->selection->preset === null && $terms !== []) {
            usort($rows, static function (mixed $left, mixed $right) use ($terms, $plan): int {
                return self::compare($left, $right, $terms, $plan instanceof CursorReadPlan, $plan->comparisons);
            });
        }

        return [$total, $rows, $terms];
    }

    /** @param list<OrderTerm> $terms */
    private static function compare(mixed $left, mixed $right, array $terms, bool $cursor = false, ?Comparisons\ResolvedComparisons $comparisons = null): int
    {
        foreach ($terms as $term) {
            $a = self::orderValue($left, $term);
            $b = self::orderValue($right, $term);
            $comparison = self::compareValue($a, $b, $term, $cursor, $comparisons);

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    private static function compareValue(string|int|float|bool|null $a, string|int|float|bool|null $b, OrderTerm $term, bool $cursor = false, ?Comparisons\ResolvedComparisons $comparisons = null): int
    {
        $comparison = $a === null || $b === null ? ($a === $b ? 0 : ($a === null ? -1 : 1)) : ($cursor && $term->valueType === ValueType::String ? strcmp((string) $a, (string) $b) : match ($term->sortType) {
            SortType::Native, SortType::Regular, SortType::Numeric => $a <=> $b,
            SortType::String => strcmp((string) $a, (string) $b),
            SortType::Natural => strnatcmp((string) $a, (string) $b),
            SortType::Locale => ($comparisons ?? throw DapterException::configuration())->compare($term->field, (string) $a, (string) $b),
        });

        return $term->direction === SortDirection::Asc ? $comparison : -$comparison;
    }

    /** @param array<int,mixed> $rows */
    private function validateRows(array $rows, ValidatedReadPlan|SimpleReadPlan|CursorReadPlan $plan): void
    {
        $orderTypes = [];
        $identities = [];
        foreach ($rows as $row) {
            foreach ($plan->options->fields as $name => $field) {
                $value = $field->output === null ? RowValue::get($row, $field->target) : RowValue::output($row, $field->output);

                if ($field->search !== null) {
                    ScalarValue::normalize($value, $field->search->valueType, true);

                    if ($field->search->valueType === ValueType::String && $value !== null && preg_match('//u', $value) !== 1) {
                        throw DapterException::configuration();
                    }
                }
                foreach ($field->filters as $filter) {
                    if ($filter->valueType !== null) {
                        ScalarValue::normalize($value, $filter->valueType, true);
                    }
                }

                if ($field->order !== null) {
                    $type = $field->order->valueType ?? ($orderTypes[$name] ?? null);

                    if (in_array(SortType::Locale, $field->order->sortTypes, true) && $value !== null && (! is_string($value) || preg_match('//u', $value) !== 1)) {
                        throw DapterException::configuration();
                    }
                    foreach ($field->order->sortTypes as $sort) {
                        $type ??= match ($sort) {
                            SortType::String, SortType::Natural, SortType::Locale => ValueType::String,
                            SortType::Numeric => ValueType::Number,
                            default => $value === null ? null : ScalarValue::infer([$value]),
                        };

                        if ($type !== null) {
                            $orderTypes[$name] = $type;
                            ScalarValue::normalize($value, $type, true);
                        }
                    }
                }
            }
            foreach ($plan->options->global->search ?? [] as $target) {
                $value = ScalarValue::normalize(RowValue::get($row, $target->target), $target->valueType, true);

                if ($target->valueType === ValueType::String && $value !== null && (! is_string($value) || preg_match('//u', $value) !== 1)) {
                    throw DapterException::configuration();
                }
            }

            if ($plan->options->query?->identity !== null) {
                $values = [];
                foreach ($plan->options->query->identity->columns as $column) {
                    $value = ScalarValue::normalize(RowValue::output($row, $column->output), $column->valueType, true);

                    if ($value === null || $value === '') {
                        throw DapterException::configuration();
                    }
                    $values[] = $value;
                }
                $identity = serialize($values);

                if (isset($identities[$identity])) {
                    throw DapterException::configuration();
                }
                $identities[$identity] = true;
            }
        }
    }

    private static function matches(mixed $row, SearchClause $search, ?Comparisons\ResolvedComparisons $comparisons, ?string $output = null): bool
    {
        $value = ScalarValue::normalize($output === null ? RowValue::get($row, $search->target) : RowValue::output($row, $output), $search->valueType, true);

        if ($value === null) {
            return false;
        }

        if ($search->valueType === ValueType::String && ! in_array($search->matchType, [MatchType::FullText, MatchType::Vector], true)) {
            if (! is_string($search->value)) {
                throw DapterException::configuration();
            }

            return (($comparisons ?? throw DapterException::configuration())->literal ?? throw DapterException::configuration())->matches((string) $value, $search->value, $search->matchType);
        }

        return match ($search->matchType) {
            MatchType::FullText, MatchType::Vector => throw DapterException::unsupported(),
            MatchType::Equals => $value === $search->value,
            MatchType::Contains => is_string($value) && is_string($search->value) && str_contains($value, $search->value),
            MatchType::StartsWith => is_string($value) && is_string($search->value) && str_starts_with($value, $search->value),
            MatchType::EndsWith => is_string($value) && is_string($search->value) && str_ends_with($value, $search->value),
        };
    }

    private static function orderValue(mixed $row, OrderTerm $term): string|int|float|bool|null
    {
        $value = $term->output === null ? RowValue::get($row, $term->target) : RowValue::output($row, $term->output);
        $type = $term->valueType ?? match ($term->sortType) {
            SortType::String, SortType::Natural, SortType::Locale => ValueType::String,
            SortType::Numeric => ValueType::Number,
            default => ScalarValue::infer([$value]),
        };

        return ScalarValue::normalize($value, $type, true);
    }
}
