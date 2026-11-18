<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;

/** @internal */
final class AutomaticOrder
{
    /** @return list<OrderTerm> */
    public static function terms(ValidatedReadPlan $plan): array
    {
        return self::forSelection($plan->options, $plan->selection);
    }

    /** @return list<OrderTerm> */
    public static function forSelection(ResolvedStoreOptions $options, ReadSelection $selection): array
    {
        $terms = [];
        foreach ($options->query->fixedOrder ?? [] as $fixed) {
            $field = $options->fields[$fixed->field];
            $terms[] = new OrderTerm($fixed->field, $field->target, $fixed->direction, $fixed->sortType, $field->order->valueType, $field->output, 'prefix');
        }
        foreach ($selection->order as $term) {
            $field = $options->fields[$term->field];
            $terms[] = new OrderTerm($term->field, $term->target, $term->direction, $term->sortType, $term->valueType, $field->output);
        }
        foreach ($options->query->identity->columns ?? [] as $column) {
            if (! in_array($column->target, array_column($terms, 'target'), true)) {
                $terms[] = new OrderTerm($column->output, $column->target, SortDirection::Asc, SortType::Native, $column->valueType, $column->output, 'tie');
            }
        }

        return $terms;
    }

    /**
     * @param  list<OrderTerm>  $terms
     * @return array<string,mixed>
     */
    public static function wire(ValidatedReadPlan|SimpleReadPlan|CursorReadPlan $plan, array $terms): array
    {
        if ($plan->selection->preset !== null) {
            return ['kind' => 'preset', 'id' => $plan->selection->preset, 'identity' => 'preset:'.$plan->selection->preset, 'opaque' => true];
        }

        return $plan->selection->order === [] ? ['kind' => 'source'] : ['kind' => 'terms', 'terms' => array_map(static fn (OrderTerm $term): array => $term->toArray(), $terms)];
    }
}
