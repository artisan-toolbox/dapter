<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @template TModel of Model */
final readonly class EloquentStoreDriver implements StoreDriver
{
    /** @var Builder<TModel> */
    private Builder $source;

    private DriverCapabilities $profile;

    /** @param Builder<TModel> $source */
    public function __construct(Builder $source)
    {
        $this->source = (clone $source)->applyScopes()->withoutGlobalScopes();
        $this->profile = new DriverCapabilities(offset: self::prepareOffset($this->source));
    }

    public function capabilities(): DriverCapabilities
    {
        return $this->profile;
    }

    public function execute(ValidatedReadPlan $plan): RawPage
    {
        if (! $this->profile->offset) {
            throw DapterException::configuration();
        }
        $scoped = $this->source;
        $total = (clone $scoped)->toBase()->getCountForPagination();

        if ($total > 9007199254740991) {
            throw DapterException::configuration();
        }
        $pages = max(1, (int) ceil($total / $plan->paging->perPage));
        $current = min($plan->paging->current, $pages);
        $rows = (clone $scoped)->offset(($current - 1) * $plan->paging->perPage)->limit($plan->paging->perPage)->get()->all();

        foreach ($rows as $row) {
            $identity = $row->getKey();

            if ((! is_int($identity) && ! is_string($identity)) || $identity === '') {
                throw DapterException::configuration();
            }
        }

        return new RawPage(array_values($rows), new OffsetRequest($current, $plan->paging->perPage), $pages, $total);
    }

    /** @param Builder<TModel> $scoped */
    private static function prepareOffset(Builder $scoped): bool
    {
        $query = $scoped->getQuery();
        $model = $scoped->getModel();
        $table = $model->getTable();
        $key = $model->getKeyName();

        if (! $query->getConnection() instanceof Connection || $query->getConnection()->getDriverName() !== 'sqlite' || ! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\z/D', $table) || ! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\z/D', $key) || $query->from !== $table || $query->joins || $query->groups || $query->havings || $query->unions || $query->distinct || $query->limit !== null || $query->offset !== null || $query->aggregate !== null || $query->groupLimit !== null || $query->unionLimit !== null || $query->unionOffset !== null || $query->unionOrders || ($query->columns !== null && $query->columns !== ['*'] && $query->columns !== [$table.'.*'])) {
            return false;
        }
        $qualifiedKey = $model->qualifyColumn($key);
        $hasKey = false;
        foreach ($query->orders ?? [] as $order) {
            $column = $order['column'] ?? null;
            $direction = $order['direction'] ?? null;

            if (($order['type'] ?? 'Basic') !== 'Basic' || ! is_string($column) || ! preg_match('/\A(?:'.preg_quote($table, '/').'\.)?[A-Za-z_][A-Za-z_0-9]*\z/D', $column) || ! is_string($direction) || SortDirection::tryFrom($direction) === null) {
                return false;
            }
            $hasKey = $hasKey || $column === $key || $column === $qualifiedKey;
        }

        if (! $hasKey) {
            $scoped->orderBy($qualifiedKey, SortDirection::Asc->value);
        }

        return true;
    }
}
