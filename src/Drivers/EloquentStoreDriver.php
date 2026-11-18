<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\EloquentSource;
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
use ArtisanToolbox\Dapter\Options\CountQueries;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use ArtisanToolbox\Dapter\Paging\CursorBoundary;
use ArtisanToolbox\Dapter\Paging\CursorExecutionContext;
use ArtisanToolbox\Dapter\Paging\StableFingerprint;
use ArtisanToolbox\Dapter\Support\FieldIdentity;
use ArtisanToolbox\Dapter\Support\RowIdentity;
use ArtisanToolbox\Dapter\Support\ScalarValue;
use ArtisanToolbox\Dapter\Support\TrustedHandlerBinder;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\Grammar;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use PDO;

/** @template TModel of Model */
readonly class EloquentStoreDriver implements IdentityLookupDriver, TraversalStoreDriver
{
    /** @var Builder<covariant Model> */
    private Builder $source;

    private DriverCapabilities $profile;

    private string $connectionIdentity;

    private Grammar $grammar;

    private ?PDO $writePdo;

    private ?PDO $readPdo;

    private bool $useWritePdo;

    /** @var array<array-key,mixed> */
    private array $mandatoryWheres;

    /** @var array<string,list<mixed>> */
    private array $mandatoryBindings;

    /** @var array<string,mixed> */
    private array $sourceShape;

    /** @var array{class:class-string<Model>,table:string,key:string,connection:?string} */
    private array $modelShape;

    /** @param Builder<covariant Model> $source */
    public function __construct(Builder $source, private ?QueryOptions $queryOptions = null)
    {
        $mandatory = self::canonical($source->getQuery());
        $original = self::isolatedQuery($source->getQuery());
        $mandatoryBindings = self::predicateBindings($source->getQuery());
        $model = $source->getModel();
        $this->modelShape = ['class' => get_class($model), 'table' => $model->getTable(), 'key' => $model->getKeyName(), 'connection' => $model->getConnectionName()];
        $this->connectionIdentity = self::connectionIdentity($source->getQuery());
        $this->grammar = $source->getQuery()->getGrammar();
        $connection = $source->getQuery()->getConnection();
        $this->writePdo = $connection instanceof Connection && self::supportedConnection($source->getQuery()) ? $connection->getPdo() : null;
        $this->readPdo = $connection instanceof Connection && self::supportedConnection($source->getQuery()) ? $connection->getReadPdo() : null;
        $this->useWritePdo = $source->getQuery()->useWritePdo;
        $this->source = self::isolated($source)->applyScopes()->withoutGlobalScopes();

        if (array_values($this->modelShape) !== [get_class($this->source->getModel()), $this->source->getModel()->getTable(), $this->source->getModel()->getKeyName(), $this->source->getModel()->getConnectionName()] || ! self::preservesShape($this->source->getQuery(), $original) || self::canonical($source->getQuery()) !== $mandatory || ! self::preservesBindings(self::predicateBindings($this->source->getQuery()), $mandatoryBindings)) {
            throw DapterException::configuration();
        }
        $query = $this->source->getQuery();
        $this->mandatoryWheres = self::isolatedQuery($query)->wheres;
        $this->mandatoryBindings = self::predicateBindings($query);
        $this->sourceShape = ['from' => $query->from, 'columns' => self::canonical($query->columns), 'joins' => self::canonical($query->joins), 'groups' => self::canonical($query->groups), 'havings' => self::canonical($query->havings)];
        $eligible = self::prepareOffset($this->source) || $queryOptions !== null && self::advancedShape($this->source, $queryOptions);
        $this->profile = new DriverCapabilities(offset: $eligible, fieldOperations: true, sourceType: EloquentSource::class, composedOperations: true, simple: $eligible, cursor: $eligible, comparisons: ! $connection instanceof Connection || $this->writePdo === null || $this->readPdo === null ? null : Comparisons\ComparisonPolicy::sql($connection->getDriverName(), $this->useWritePdo ? $this->writePdo : $this->readPdo, function (): void {
            $this->guard($this->source);
        }), literalSearchPolicy: true);
    }

    /** @internal */
    public function database(): string
    {
        $this->guard($this->source, eligibility: false);
        $connection = $this->source->getQuery()->getConnection();

        if (! $connection instanceof Connection) {
            throw DapterException::configuration();
        }

        return $connection->getDriverName();
    }

    /** @internal */
    public function rowIdentity(?string $field): RowIdentityProvider
    {
        $model = $this->source->getModel();
        $attribute = $field ?? $model->getKeyName();

        return new FieldIdentity($field, new SqlIdentitySelector($model->qualifyColumn($attribute), $attribute));
    }

    /**
     * @param  list<int|string>  $publicIds
     * @return list<mixed>
     */
    public function identifiedRows(RowIdentityProvider $identity, array $publicIds): array
    {
        $selector = $identity->selector() ?? throw DapterException::configuration();
        $wanted = [];
        $reverse = [];
        $keys = [];
        foreach ($publicIds as $id) {
            $value = $identity->lookup($id);

            if ($value === null) {
                continue;
            }
            $value = RowIdentity::validate($value);
            $key = RowIdentity::key($value);

            if (isset($keys[$key])) {
                throw DapterException::configuration();
            }
            $keys[$key] = true;
            $wanted[RowIdentity::key($id)] = true;
            $reverse[] = $value;
        }

        if ($reverse === []) {
            return [];
        }
        $query = $this->copy($this->source);
        WherePredicates::group($query);
        $query->whereIn($selector->column, $reverse)->limit(101);
        $this->guard($query, bounded: true);
        $rows = array_values($query->get()->all());

        if (count($rows) > 100) {
            throw DapterException::configuration();
        }
        $seen = [];
        foreach ($rows as $row) {
            $key = RowIdentity::key(RowIdentity::validate($identity->identify($row)));

            if (isset($seen[$key]) || ! isset($wanted[$key]) && ! $identity instanceof FieldIdentity) {
                throw DapterException::configuration();
            }
            $seen[$key] = true;
        }

        return array_values(array_filter($rows, static fn (mixed $row): bool => isset($wanted[RowIdentity::key($identity->identify($row))])));
    }

    public function capabilities(): DriverCapabilities
    {
        return $this->profile;
    }

    /**
     * @internal
     *
     * @return self<*>
     */
    public function withQueryOptions(?QueryOptions $options): self
    {
        if ($options === $this->queryOptions) {
            return $this;
        }
        $this->guard($this->source, eligibility: false);
        $copy = self::isolated($this->source);
        $this->guard($copy, eligibility: false);

        return new self($copy, $options);
    }

    public function execute(ValidatedReadPlan $plan): RawPage
    {
        if ($plan->options->query !== $this->queryOptions) {
            return $this->withQueryOptions($plan->options->query)->execute($plan);
        }
        [$base, $scoped, $terms] = $this->prepare($plan);
        $counts = $this->exactCounts($plan, $base, $scoped);
        $total = $counts->total;
        $filtered = $counts->filtered;
        $pages = max(1, (int) ceil($filtered / $plan->paging->perPage));
        $current = min($plan->paging->current, $pages);
        $offset = ($current - 1) * $plan->paging->perPage;
        $rowsQuery = $this->copy($scoped)->offset($offset)->limit($plan->paging->perPage);
        $this->guard($rowsQuery, bounded: true);
        $rows = $rowsQuery->get()->all();

        if (count($rows) !== min($plan->paging->perPage, max(0, $filtered - $offset))) {
            throw DapterException::configuration();
        }
        foreach ($rows as $row) {
            foreach ($this->queryOptions->identity->columns ?? [] as $column) {
                $value = ScalarValue::normalize($row->getAttribute($column->output), $column->valueType, true);

                if ($value === null || $value === '') {
                    throw DapterException::configuration();
                }
            }

            if ($this->queryOptions?->identity === null) {
                $identity = $row->getKey();

                if ((! is_int($identity) && ! is_string($identity)) || $identity === '') {
                    throw DapterException::configuration();
                }
            }
        }

        return new RawPage(array_values($rows), new OffsetRequest($current, $plan->paging->perPage), $pages, $total, $filtered, AutomaticOrder::wire($plan, $terms));
    }

    public function traverse(SimpleReadPlan|CursorReadPlan $plan): SimplePage|CursorPage
    {
        if ($plan->options->query !== $this->queryOptions) {
            return $this->withQueryOptions($plan->options->query)->traverse($plan);
        }

        if ($plan->selection->preset !== null) {
            throw DapterException::unsupported();
        }

        if ($plan instanceof CursorReadPlan) {
            $this->validateCursorShape($this->source, AutomaticOrder::forSelection($plan->options, $plan->selection));
        }
        [$base, $scoped, $terms] = $this->prepare($plan);
        $counts = null;

        if ($plan instanceof SimpleReadPlan) {
            if ($this->queryOptions?->identity !== null && ! $plan->options->exactCounts) {
                $this->uniqueIdentity($base);
                $this->uniqueIdentity($scoped);
            }

            if ($plan->options->exactCounts) {
                $counts = $this->exactCounts($plan, $base, $scoped);
            }
            $query = $this->copy($scoped)->offset(($plan->paging->current - 1) * $plan->paging->perPage)->limit($plan->paging->perPage + 1);
            $this->guard($query, bounded: true);
            $rows = array_values($query->get()->all());

            return new SimplePage(array_slice($rows, 0, $plan->paging->perPage), $plan->paging, $plan->paging->current > 1, count($rows) > $plan->paging->perPage, $counts, AutomaticOrder::wire($plan, $terms));
        }
        $effectiveOrder = AutomaticOrder::wire($plan, $terms);
        $terms = $this->cursorTerms($scoped, $terms);
        $context = new CursorExecutionContext($this->fingerprint($this->source), $this->fingerprint($scoped), $terms);
        $boundary = $plan->cursorAccess->accept($context);
        $this->cursorEligibility($scoped, $terms);

        if ($plan->options->exactCounts) {
            $counts = $this->exactCounts($plan, $base, $scoped);
        }
        $query = $this->copy($scoped)->reorder();
        $forward = $boundary === null ? true : $boundary->forward;
        $cursor = $boundary === null ? null : new Cursor(array_combine(array_column($terms, 'output'), $boundary->values), $forward);
        foreach ($terms as $term) {
            $direction = $term->direction;

            if (! $forward) {
                $direction = $direction === SortDirection::Asc ? SortDirection::Desc : SortDirection::Asc;
            }
            $query->orderBy($term->target, $direction->value);
        }

        if ($boundary !== null) {
            $this->bound($query, $terms, $boundary);
        }
        $query->limit($plan->paging->perPage + 1);
        $this->guard($query, bounded: true);
        $paginator = new CursorPaginator($query->get(), $plan->paging->perPage, $cursor);
        $rows = array_values($paginator->items());
        foreach ($rows as $row) {
            $this->rawTuple($row, $terms);
        }
        $previous = $rows !== [] && ! $paginator->onFirstPage() ? $plan->cursorAccess->seal(new CursorBoundary($this->rawTuple($rows[0], $terms), false), $context) : null;
        $next = $rows !== [] && $paginator->hasMorePages() ? $plan->cursorAccess->seal(new CursorBoundary($this->rawTuple($rows[array_key_last($rows)], $terms), true), $context) : null;

        return new CursorPage($rows, $plan->paging, $previous, $next, $counts, $effectiveOrder);
    }

    /** @param Builder<covariant Model> $builder */
    private function fingerprint(Builder $builder): string
    {
        $query = $builder->getQuery();
        $connection = $query->getConnection();

        if (! $connection instanceof Connection) {
            throw DapterException::configuration();
        }

        return StableFingerprint::of([$query->toSql(), $connection->prepareBindings($query->getBindings()), $this->modelShape, $connection->getName(), $connection->getDatabaseName(), $connection->getDriverName(), get_class($query->getGrammar()), $connection->getTablePrefix()]);
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @param  list<OrderTerm>  $declared
     * @return non-empty-list<OrderTerm>
     */
    private function cursorTerms(Builder $builder, array $declared): array
    {
        $selected = $this->validateCursorShape($builder, $declared);
        $projection = $this->projection($builder);
        $terms = [];
        $orders = $builder->getQuery()->orders ?? [];

        if ($orders === []) {
            $orders[] = ['column' => $builder->getModel()->getQualifiedKeyName(), 'direction' => 'asc'];
        }
        foreach ($orders as $order) {
            $ordered = $order['column'] ?? null;

            if (! is_string($ordered)) {
                throw DapterException::unsupported();
            }
            $target = $this->cursorTarget($builder, $ordered);

            if (! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*(?:\.[A-Za-z_][A-Za-z_0-9]*)?\z/D', $target)) {
                throw DapterException::unsupported();
            }
            $found = null;
            foreach ($declared as $term) {
                $actual = $this->cursorTarget($builder, $term->target);

                if ($actual === $target) {
                    $found = $term;

                    break;
                }
            }

            if (in_array($target, array_column($terms, 'target'), true)) {
                continue;
            }
            $plain = str_contains($target, '.') ? substr($target, strrpos($target, '.') + 1) : $target;
            $outputs = array_map(fn (string $original): string => $this->cursorTarget($builder, $original), $projection);
            $output = $found->output ?? array_search($target, $outputs, true);
            $output = is_string($output) ? $output : $plain;

            if (($selected[$output] ?? null) !== $target) {
                throw DapterException::unsupported();
            }
            $schema = $this->cursorColumn($builder, $target);
            $type = $found->valueType ?? $this->cursorType($schema['type_name']);
            $terms[] = new OrderTerm($found->field ?? $output, $target, SortDirection::from($order['direction']), SortType::Native, $type, $output, $found?->role);
        }
        $identities = $this->queryOptions->identity->columns ?? [new IdentityColumn($builder->getModel()->getQualifiedKeyName(), $builder->getModel()->getKeyName(), $this->cursorType($this->cursorColumn($builder, $builder->getModel()->getQualifiedKeyName())['type_name']))];
        foreach ($identities as $identity) {
            $target = $this->cursorTarget($builder, $identity->target);

            if (! in_array($target, array_column($terms, 'target'), true)) {
                if (($selected[$identity->output] ?? null) !== $target) {
                    throw DapterException::unsupported();
                }
                $terms[] = new OrderTerm($identity->output, $target, SortDirection::Asc, SortType::Native, $identity->valueType, $identity->output, 'tie');
            }
        }
        $groups = $builder->getQuery()->groups;
        foreach ($terms as $term) {
            $this->cursorColumn($builder, $term->target);

            if ($groups !== null && ! in_array($term->target, array_map(fn (string $group): string => $this->cursorTarget($builder, $group), $groups), true)) {
                throw DapterException::unsupported();
            }
        }

        if ($terms === []) {
            throw DapterException::unsupported();
        }

        return $terms;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @param  list<OrderTerm>  $declared
     * @return array<string,string>
     */
    private function validateCursorShape(Builder $builder, array $declared): array
    {
        if (((array) $builder)["\0*\0afterQueryCallbacks"]) {
            throw DapterException::unsupported();
        }
        $selected = $this->cursorProjection($builder);
        foreach ($declared as $term) {
            $target = $this->cursorTarget($builder, $term->target);

            if (! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*(?:\.[A-Za-z_][A-Za-z_0-9]*)?\z/D', $target)) {
                throw DapterException::unsupported();
            }
        }

        return $selected;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return array<string,string>
     */
    private function cursorProjection(Builder $builder): array
    {
        $outputs = [];
        $tables = $this->cursorTables($builder);
        $connection = $builder->getQuery()->getConnection();

        if (! $connection instanceof Connection) {
            throw DapterException::configuration();
        }
        foreach ($builder->getQuery()->columns ?? ['*'] as $column) {
            if ($column instanceof \Illuminate\Contracts\Database\Query\Expression) {
                $column = $column->getValue($builder->getQuery()->getGrammar());
            }

            if (! is_string($column)) {
                throw DapterException::unsupported();
            }
            $selected = [];

            if ($column === '*' || str_ends_with($column, '.*')) {
                $aliases = $column === '*' ? array_keys($tables) : [substr($column, 0, -2)];
                foreach ($aliases as $alias) {
                    $table = $tables[$alias] ?? throw DapterException::unsupported();
                    foreach ($connection->getSchemaBuilder()->getColumns($table) as $schema) {
                        $selected[] = [$schema['name'], $alias.'.'.$schema['name']];
                    }
                }
            } elseif (preg_match('/\A(.+)\s+as\s+([A-Za-z_][A-Za-z_0-9]*)\z/iD', $column, $match)) {
                $selected[] = [$match[2], str_contains($match[1], '.') ? $match[1] : self::baseAlias($builder).'.'.$match[1]];
            } else {
                $selected[] = [str_contains($column, '.') ? substr($column, strrpos($column, '.') + 1) : $column, str_contains($column, '.') ? $column : self::baseAlias($builder).'.'.$column];
            }
            foreach ($selected as [$output, $target]) {
                if (isset($outputs[$output]) && $outputs[$output] !== $target) {
                    throw DapterException::unsupported();
                }
                $outputs[$output] = $target;
            }
        }

        return $outputs;
    }

    /** @param Builder<covariant Model> $builder */
    private function cursorTarget(Builder $builder, string $target): string
    {
        $original = $this->projection($builder)[$target] ?? $target;

        return str_contains($original, '.') ? $original : self::baseAlias($builder).'.'.$original;
    }

    private function cursorType(string $native): ValueType
    {
        return match (strtolower($native)) {
            'int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'int2', 'int4', 'int8', 'serial', 'smallserial', 'bigserial' => ValueType::Integer,
            'real', 'float', 'float4', 'float8', 'double', 'double precision', 'decimal', 'numeric' => ValueType::Number,
            'bool', 'boolean' => ValueType::Boolean,
            'char', 'character', 'bpchar', 'varchar', 'character varying', 'text', 'tinytext', 'mediumtext', 'longtext', 'clob', 'enum', 'uuid', 'date', 'time', 'timetz', 'timestamp', 'timestamptz', 'datetime', 'year', 'interval' => ValueType::String,
            default => throw DapterException::unsupported(),
        };
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return array<string,string>
     */
    private function cursorTables(Builder $builder): array
    {
        $tables = [self::baseAlias($builder) => $builder->getModel()->getTable()];
        foreach ($builder->getQuery()->joins ?? [] as $join) {
            if (! is_string($join->table) || ! preg_match('/\A([A-Za-z_][A-Za-z_0-9]*)(?:\s+(?:as\s+)?([A-Za-z_][A-Za-z_0-9]*))?\z/iD', $join->table, $match)) {
                throw DapterException::unsupported();
            }
            $tables[$match[2] ?? $match[1]] = $match[1];
        }

        return $tables;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return array<string,mixed>
     */
    private function cursorColumn(Builder $builder, string $target): array
    {
        $tables = $this->cursorTables($builder);
        $parts = explode('.', $target);
        $alias = count($parts) === 1 ? self::baseAlias($builder) : $parts[0];
        $name = count($parts) === 1 ? $parts[0] : $parts[1];

        if (! isset($tables[$alias])) {
            throw DapterException::unsupported();
        }
        $connection = $builder->getQuery()->getConnection();

        if (! $connection instanceof Connection) {
            throw DapterException::configuration();
        }
        $columns = array_column($connection->getSchemaBuilder()->getColumns($tables[$alias]), null, 'name');

        return $columns[$name] ?? throw DapterException::unsupported();
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @param  non-empty-list<OrderTerm>  $terms
     */
    private function cursorEligibility(Builder $builder, array $terms): void
    {
        $copy = $this->copy($builder)->reorder();
        $query = $copy->getQuery();
        $connection = $query->getConnection();

        if (! $connection instanceof Connection) {
            throw DapterException::configuration();
        }
        $outputs = array_column($terms, 'output');
        $invalid = $this->routedQuery($connection)->fromSub($query, 'dapter_cursor')->where(function (QueryBuilder $nulls) use ($outputs): void {
            foreach ($outputs as $output) {
                $nulls->orWhereNull($output);
            }
        })->limit(1);
        $this->guard($copy);

        if ($invalid->exists()) {
            throw DapterException::unsupported();
        }

        if ($this->queryOptions?->identity !== null) {
            $this->uniqueIdentity($copy);
        } else {
            $output = $builder->getModel()->getKeyName();
            $duplicate = $this->routedQuery($connection)->fromSub($query, 'dapter_cursor')->select($output)->groupBy($output)->havingRaw('count(*) > 1')->limit(1);
            $this->guard($copy);

            if ($duplicate->exists()) {
                throw DapterException::unsupported();
            }
        }
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @param  non-empty-list<OrderTerm>  $terms
     */
    private function bound(Builder $builder, array $terms, CursorBoundary $boundary): void
    {
        if (count($terms) !== count($boundary->values)) {
            throw DapterException::validation();
        }
        $builder->where(static function (Builder $bounds) use ($terms, $boundary): void {
            foreach ($terms as $index => $term) {
                $bounds->orWhere(static function (Builder $branch) use ($terms, $boundary, $index, $term): void {
                    for ($equal = 0; $equal < $index; $equal++) {
                        $branch->where($terms[$equal]->target, '=', $boundary->values[$equal]);
                    }
                    $greater = ($term->direction === SortDirection::Asc) === $boundary->forward;
                    $branch->where($term->target, $greater ? '>' : '<', $boundary->values[$index]);
                });
            }
        });
    }

    /**
     * @param  non-empty-list<OrderTerm>  $terms
     * @return non-empty-list<string|int|float|bool>
     */
    private function rawTuple(Model $row, array $terms): array
    {
        $values = [];
        foreach ($terms as $term) {
            $value = $row->getRawOriginal($term->output);
            $valid = match ($term->valueType) {
                ValueType::String => is_string($value),
                ValueType::Integer => is_int($value) || is_string($value) && preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value),
                ValueType::Number => is_int($value) || is_float($value) && is_finite($value) || is_string($value) && preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/D', $value),
                ValueType::Boolean => is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1',
                null => false,
            };

            if (! $valid) {
                throw DapterException::unsupported();
            }
            $values[] = $value;
        }

        return $values;
    }

    /** @return array{Builder<covariant Model>,Builder<covariant Model>,list<OrderTerm>} */
    private function prepare(ValidatedReadPlan|SimpleReadPlan|CursorReadPlan $plan): array
    {
        $this->guard($this->source);

        if ($plan instanceof CursorReadPlan) {
            $plan->cursorAccess->assertBaseScope($this->fingerprint($this->source));
        }
        $this->validateFields($plan->options);
        $base = $this->copy($this->source);
        $scoped = $this->copy($this->source);
        WherePredicates::group($scoped);
        $binder = new TrustedHandlerBinder;
        foreach ($plan->options->global->filters ?? [] as $definition) {
            $binder->signature($binder->describe($definition->apply), Builder::class, 2);
        }
        foreach ($plan->options->global->orders ?? [] as $definition) {
            $binder->signature($binder->describe($definition->apply), Builder::class, 1);
        }

        if (($plan instanceof ValidatedReadPlan || $plan->options->exactCounts) && $this->queryOptions?->counts !== null) {
            $binder->signature($binder->describe($this->queryOptions->counts), Builder::class, 2, CountQueries::class);
        }
        $handlers = [];
        foreach ($plan->selection->globalFilters as $id => $values) {
            $definition = $plan->options->global->filters[$id];
            $handler = $binder->describe($definition->apply);
            $handlers[] = [$handler, $binder->bindValue($handler, $definition->multiple ? $values : $values[0], $definition->options)];
        }
        foreach ($handlers as [$handler, $value]) {
            $scoped = $this->transformed($binder->invoke($handler, [$this->copy($scoped), $value]));
        }

        if ($plan->selection->preset !== null) {
            $preset = $this->copy($scoped)->reorder();
            $prefix = [];
            foreach ($plan->options->query->fixedOrder ?? [] as $fixed) {
                $column = $this->column($preset, $plan->options->fields[$fixed->field]->target);
                $preset->orderBy($column, $fixed->direction->value);
                $prefix[] = ['column' => $column, 'direction' => $fixed->direction->value];
            }
            $membership = self::canonical(self::isolatedQuery($preset->getQuery())->reorder());
            $scoped = $this->transformed($binder->invoke($binder->describe($plan->options->global->orders[$plan->selection->preset]->apply), [$preset]));
            $orders = array_map(fn (array $order): array => ['column' => $this->column($scoped, $order['column']), 'direction' => $order['direction']], $scoped->getQuery()->orders ?? []);
            $identity = $this->queryOptions?->identity === null ? [$scoped->getModel()->getQualifiedKeyName()] : array_map(fn (IdentityColumn $column): string => $this->column($scoped, $column->target), $this->queryOptions->identity->columns);

            if (self::canonical(self::isolatedQuery($scoped->getQuery())->reorder()) !== $membership || array_slice($orders, 0, count($prefix)) !== $prefix || array_diff($identity, array_column($orders, 'column')) !== []) {
                throw DapterException::configuration();
            }
        }

        if ($plan->selection->searches !== [] || $plan->selection->globalSearch !== null) {
            $scoped->where(function (Builder $searchGroup) use ($plan): void {
                $boolean = $plan->selection->searchMatch === SearchMatch::Any ? 'or' : 'and';
                foreach ($plan->selection->searches as $search) {
                    self::search($searchGroup, $search, $boolean, $plan->comparisons);
                }

                if ($plan->selection->globalSearch !== null) {
                    $searchGroup->where(function (Builder $global) use ($plan): void {
                        if ($plan->selection->globalSearches === []) {
                            $global->whereRaw('0 = 1');
                        }
                        foreach ($plan->selection->globalSearches as $search) {
                            self::search($global, $search, 'or', $plan->comparisons, true);
                        }
                    }, boolean: $boolean);
                }
            });
        }
        foreach ($plan->selection->filters as $filter) {
            $column = $this->column($scoped, $filter->target);
            $scoped->where(static function (Builder $nested) use ($filter, $column): void {
                $nonnull = array_values(array_filter($filter->values, static fn (mixed $value): bool => $value !== null));

                if ($nonnull !== []) {
                    $nested->whereIn($column, $nonnull);
                }

                if (in_array(null, $filter->values, true)) {
                    $nested->orWhereNull($column);
                }
            });
        }
        $terms = AutomaticOrder::forSelection($plan->options, $plan->selection);

        if ($plan->selection->preset === null && $terms !== []) {
            $sourceOrders = $plan->selection->order === [] ? $scoped->getQuery()->orders ?? [] : [];
            $scoped->reorder();
            foreach ($terms as $term) {
                if ($plan->selection->order !== [] || $term->role !== 'tie') {
                    $scoped->orderBy($this->column($scoped, $term->target), $term->direction->value);
                }
            }
            foreach ($sourceOrders as $order) {
                $scoped->orderBy($order['column'], $order['direction']);
            }

            if ($plan->selection->order === []) {
                foreach ($terms as $term) {
                    $column = $this->column($scoped, $term->target);
                    $ordered = array_map(fn (array $order): string => $this->column($scoped, $order['column']), $scoped->getQuery()->orders ?? []);

                    if ($term->role === 'tie' && ! in_array($column, $ordered, true)) {
                        $scoped->orderBy($column, $term->direction->value);
                    }
                }
            }

            if ($plan->options->query?->identity === null && ! in_array($scoped->getModel()->getQualifiedKeyName(), array_map(fn (OrderTerm $term): string => $this->column($scoped, $term->target), $terms), true)) {
                $scoped->orderBy($scoped->getModel()->getQualifiedKeyName());
            }
        }
        $this->guard($scoped);

        return [$base, $scoped, $terms];
    }

    /**
     * @param  Builder<covariant Model>  $base
     * @param  Builder<covariant Model>  $scoped
     */
    private function exactCounts(ValidatedReadPlan|SimpleReadPlan|CursorReadPlan $plan, Builder $base, Builder $scoped): ExactCounts
    {
        $binder = new TrustedHandlerBinder;

        if ($this->queryOptions?->counts !== null) {
            $counts = $binder->invoke($binder->describe($this->queryOptions->counts), [$this->copy($base), $this->copy($scoped)]);

            if (! $counts instanceof CountQueries) {
                throw DapterException::configuration();
            }
            $totalCount = $this->countQuery($counts->total);
            $filteredCount = $this->countQuery($counts->filtered);

            $filteredBindings = $scoped->getQuery()->getRawBindings()['where'];

            if (! self::preservesPredicates($filteredCount->getQuery()->wheres, $scoped->getQuery()->wheres) || array_slice($filteredCount->getQuery()->getRawBindings()['where'], 0, count($filteredBindings)) !== $filteredBindings) {
                throw DapterException::configuration();
            }

            if ($this->queryOptions->identity !== null) {
                $this->uniqueIdentity($base);
                $this->uniqueIdentity($scoped);
            }
            $total = $this->customCount($totalCount);
            $filtered = $this->customCount($filteredCount);
        } else {
            if ($this->queryOptions?->identity !== null) {
                $this->uniqueIdentity($base);
                $this->uniqueIdentity($scoped);
            }
            $total = $this->count($base);
            $filtered = $plan->selection->searches === [] && $plan->selection->globalSearch === null && $plan->selection->filters === [] && $plan->selection->globalFilters === [] ? $total : $this->count($scoped);
        }

        if ($filtered > $total) {
            throw DapterException::configuration();
        }

        return new ExactCounts($total, $filtered);
    }

    /** @param Builder<covariant Model> $query */
    private static function search(Builder $query, SearchClause $search, string $boolean, ?Comparisons\ResolvedComparisons $comparisons, bool $global = false): void
    {
        $column = str_contains($search->target, '.') ? $search->target : self::baseAlias($query).'.'.$search->target;

        if ($search->matchType === MatchType::FullText) {
            $configuration = ($comparisons ?? throw DapterException::configuration())->configuration($search->field, $global);
            $query->whereRaw(new Comparisons\FullTextExpression($column), [$configuration, $configuration, $search->value], $boolean);

            return;
        }

        if ($search->valueType !== ValueType::String) {
            $query->where($column, '=', $search->value, $boolean);

            return;
        }
        $literal = ($comparisons ?? throw DapterException::configuration())->literal ?? throw DapterException::configuration();
        $query->whereRaw($literal->expression($column, $search->matchType), $literal->bindings((string) $search->value, $search->matchType), $boolean);
    }

    /** @param Builder<covariant Model> $builder */
    private function column(Builder $builder, string $target): string
    {
        if (str_contains($target, '.') || isset($this->projection($builder)[$target])) {
            return $target;
        }

        return self::baseAlias($builder).'.'.$target;
    }

    /** @param Builder<covariant Model> $builder */
    private static function baseAlias(Builder $builder): string
    {
        $from = $builder->getQuery()->from;

        if (! is_string($from)) {
            throw DapterException::configuration();
        }

        return preg_split('/\\s+(?:as\\s+)?/i', $from)[1] ?? $builder->getModel()->getTable();
    }

    /** @return Builder<covariant Model> */
    private function transformed(mixed $builder): Builder
    {
        if (! $builder instanceof Builder) {
            throw DapterException::configuration();
        }
        $this->guard($builder);
        $builder = self::isolated($builder)->applyScopes()->withoutGlobalScopes();
        $this->guard($builder);

        return $builder;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return Builder<covariant Model>
     */
    private function copy(Builder $builder): Builder
    {
        $copy = self::isolated($builder);
        $this->guard($copy);

        return $copy;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return Builder<covariant Model>
     */
    private static function isolated(Builder $builder): Builder
    {
        $copy = clone $builder;
        $query = self::isolatedQuery($copy->getQuery());
        $copy->setModel(clone $copy->getModel());
        $copy->setQuery($query);

        return $copy;
    }

    private static function isolatedQuery(QueryBuilder $query): QueryBuilder
    {
        $copy = clone $query;
        foreach ($copy->wheres as &$where) {
            if (isset($where['query']) && $where['query'] instanceof QueryBuilder) {
                $where['query'] = self::isolatedQuery($where['query']);
            }
        }
        unset($where);

        if ($copy->joins !== null) {
            $copy->joins = array_map(static fn (QueryBuilder $join): QueryBuilder => self::isolatedQuery($join), $copy->joins);
        }

        return $copy;
    }

    /** @param Builder<covariant Model> $builder */
    private function count(Builder $builder): int
    {
        $copy = $this->copy($builder);
        $this->guard($copy);

        return self::safeCount($copy->toBase()->getCountForPagination());
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return Builder<covariant Model>
     */
    private function countQuery(Builder $builder): Builder
    {
        $this->guard($builder, true);
        $copy = self::isolated($builder)->applyScopes()->withoutGlobalScopes();
        $this->guard($copy, true);
        $columns = $copy->getQuery()->columns;
        $aggregate = count($columns ?? []) === 1 && $columns[0] instanceof Expression ? $columns[0]->getValue($copy->getQuery()->getGrammar()) : null;

        if (! is_string($aggregate) || ! preg_match('/\\Acount\\(\\*\\)\\s+as\\s+aggregate\\z/iD', $aggregate)) {
            throw DapterException::configuration();
        }

        return $copy;
    }

    /** @param Builder<covariant Model> $builder */
    private function customCount(Builder $builder): int
    {
        $copy = $this->countQuery($builder);
        $rows = $copy->toBase()->get();

        if ($rows->count() !== 1 || ! property_exists($rows[0], 'aggregate')) {
            throw DapterException::configuration();
        }
        $count = $rows[0]->aggregate;

        if (is_string($count) && preg_match('/\\A(?:0|[1-9][0-9]*)\\z/D', $count)) {
            $count = filter_var($count, FILTER_VALIDATE_INT);
        }

        return self::safeCount($count);
    }

    private static function safeCount(mixed $value): int
    {
        if (! is_int($value) || $value < 0 || $value > 9007199254740991) {
            throw DapterException::configuration();
        }

        return $value;
    }

    /** @param Builder<covariant Model> $builder */
    private function uniqueIdentity(Builder $builder): void
    {
        $copy = $this->copy($builder)->reorder();
        $this->guard($copy);
        $query = $copy->getQuery();
        $columns = $this->queryOptions->identity->columns;
        $outputs = array_map(static fn (IdentityColumn $column): string => $column->output, $columns);
        $connection = $query->getConnection();

        if (! $connection instanceof Connection) {
            throw DapterException::configuration();
        }
        $duplicate = $this->routedQuery($connection)->fromSub($query, 'dapter_identity')->select($outputs)->groupBy($outputs)->havingRaw('count(*) > 1')->limit(1);
        $invalid = $this->routedQuery($connection)->fromSub($query, 'dapter_identity')->where(static function (QueryBuilder $invalid) use ($columns): void {
            foreach ($columns as $column) {
                $invalid->orWhereNull($column->output);

                if ($column->valueType === ValueType::String) {
                    $invalid->orWhere($column->output, '=', '');
                } elseif ($column->valueType === ValueType::Integer || $column->valueType === ValueType::Number) {
                    $invalid->whereRaw(new IdentityRangeExpression($column->output), [], 'or');
                }
            }
        })->limit(1);
        $this->guard($copy);

        if ($duplicate->exists()) {
            throw DapterException::configuration();
        }
        $this->guard($copy);

        if ($invalid->exists()) {
            throw DapterException::configuration();
        }
    }

    private function routedQuery(Connection $connection): QueryBuilder
    {
        $query = $connection->query();
        $query->useWritePdo = $this->useWritePdo;

        return $query;
    }

    /** @param Builder<covariant Model> $builder */
    private function guard(Builder $builder, bool $count = false, bool $bounded = false, bool $eligibility = true): void
    {
        $query = $builder->getQuery();
        $connection = $query->getConnection();
        $model = $builder->getModel();

        if (! $connection instanceof Connection || $eligibility && ! $this->profile->offset || ! self::supportedConnection($query) || $query->getGrammar() !== $this->grammar || $query->useWritePdo !== $this->useWritePdo || $this->connectionIdentity !== self::connectionIdentity($query) || $this->writePdo !== $connection->getPdo() || $this->readPdo !== $connection->getReadPdo() || get_class($model) !== $this->modelShape['class'] || $model->getTable() !== $this->modelShape['table'] || $model->getKeyName() !== $this->modelShape['key'] || $model->getConnectionName() !== $this->modelShape['connection'] || $model->getConnection() !== $connection || $query->from !== $this->sourceShape['from'] || ! self::preservesPredicates($query->wheres, $this->mandatoryWheres) || (! $count && self::canonical($query->columns) !== $this->sourceShape['columns']) || $query->unions || $query->distinct || $query->aggregate !== null || $query->groupLimit !== null || $query->unionLimit !== null || $query->unionOffset !== null || $query->unionOrders || $query->beforeQueryCallbacks || (! $bounded && ($query->limit !== null || $query->offset !== null))) {
            throw DapterException::configuration();
        }

        if ($this->profile->comparisons?->requiresExecutionGuard() && ((array) $connection)["\0*\0beforeExecutingCallbacks"] !== []) {
            throw DapterException::configuration();
        }
        $this->profile->comparisons?->guard();

        if (! self::preservesBindings(self::predicateBindings($query), $this->mandatoryBindings)) {
            throw DapterException::configuration();
        }
        foreach ($query->orders ?? [] as $order) {
            if (($order['type'] ?? 'Basic') !== 'Basic' || ! is_string($order['column'] ?? null) || ! preg_match('/\\A[A-Za-z_][A-Za-z_0-9]*(?:\\.[A-Za-z_][A-Za-z_0-9]*)?\\z/D', $order['column']) || SortDirection::tryFrom($order['direction'] ?? '') === null) {
                throw DapterException::configuration();
            }
        }

        if (self::canonical($query->joins) !== $this->sourceShape['joins'] || self::canonical($query->groups) !== $this->sourceShape['groups'] || self::canonical($query->havings) !== $this->sourceShape['havings']) {
            throw DapterException::configuration();
        }
    }

    private static function preservesShape(QueryBuilder $actual, QueryBuilder $mandatory): bool
    {
        foreach (['aggregate', 'distinct', 'limit', 'offset', 'groupLimit', 'unions', 'unionLimit', 'unionOffset', 'unionOrders', 'beforeQueryCallbacks'] as $property) {
            if (self::canonical($actual->{$property}) !== self::canonical($mandatory->{$property})) {
                return false;
            }
        }

        return self::canonical($actual->from) === self::canonical($mandatory->from)
            && self::preservesPrefix($actual->columns ?? ['*'], $mandatory->columns ?? ['*'])
            && self::preservesPrefix($actual->joins ?? [], $mandatory->joins ?? [])
            && self::preservesPrefix($actual->groups ?? [], $mandatory->groups ?? [])
            && self::preservesPredicates($actual->wheres, $mandatory->wheres)
            && self::preservesPredicates($actual->havings ?? [], $mandatory->havings ?? [], 'havings');
    }

    /**
     * @param  array<array-key,mixed>  $actual
     * @param  array<array-key,mixed>  $mandatory
     */
    private static function preservesPrefix(array $actual, array $mandatory): bool
    {
        return self::canonical(array_slice($actual, 0, count($mandatory))) === self::canonical($mandatory);
    }

    /** @return array<string,list<mixed>> */
    private static function predicateBindings(QueryBuilder $query): array
    {
        return array_map(static fn (array $bindings): array => array_values($query->getConnection()->prepareBindings($bindings)), array_intersect_key($query->getRawBindings(), array_flip(['where', 'join', 'having'])));
    }

    /**
     * @param  array<string,list<mixed>>  $actual
     * @param  array<string,list<mixed>>  $mandatory
     */
    private static function preservesBindings(array $actual, array $mandatory): bool
    {
        foreach ($mandatory as $bucket => $bindings) {
            if (array_slice($actual[$bucket], 0, count($bindings)) !== $bindings) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<array-key,mixed>  $actual
     * @param  array<array-key,mixed>  $mandatory
     * @param  'wheres'|'havings'  $property
     */
    private static function preservesPredicates(array $actual, array $mandatory, string $property = 'wheres'): bool
    {
        if ($mandatory === [] || self::canonical($actual) === self::canonical($mandatory)) {
            return true;
        }

        if (self::canonical(array_slice($actual, 0, count($mandatory))) === self::canonical($mandatory)) {
            $conjunctions = array_slice($actual, count($mandatory));

            if (array_all($conjunctions, static fn (array $where): bool => ($where['boolean'] ?? 'and') === 'and')) {
                return true;
            }
        }
        foreach ($actual as $index => $where) {
            if ($index > 0 && ($where['boolean'] ?? 'and') !== 'and') {
                return false;
            }
        }
        foreach ($actual as $where) {
            if (($where['type'] ?? null) === 'Nested' && self::preservesPredicates($where['query']->{$property} ?? [], $mandatory, $property)) {
                return true;
            }
        }

        return false;
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof QueryBuilder) {
            return self::canonical([$value->from, $value->columns, $value->wheres, $value->getBindings(), $value->joins, $value->groups, $value->havings, $value->orders, $value->limit, $value->offset, $value->unions, $value->unionOrders, $value->unionLimit, $value->unionOffset, $value->distinct, $value->aggregate, $value->groupLimit, self::connectionIdentity($value), spl_object_id($value->getGrammar()), $value->beforeQueryCallbacks]);
        }

        if ($value instanceof \Illuminate\Contracts\Database\Query\Expression) {
            return [get_class($value), (array) $value];
        }

        if (is_array($value)) {
            return array_map(self::canonical(...), $value);
        }

        return $value;
    }

    private static function connectionIdentity(QueryBuilder $query): string
    {
        $connection = $query->getConnection();

        if (! $connection instanceof Connection) {
            return 'unsupported';
        }

        return json_encode([spl_object_id($connection), $connection->getName(), $connection->getDatabaseName(), $connection->getTablePrefix(), $connection->getConfig('driver'), $connection->getConfig('host'), $connection->getConfig('port')], JSON_THROW_ON_ERROR);
    }

    private static function supportedConnection(QueryBuilder $query): bool
    {
        $connection = $query->getConnection();
        $expected = match (get_class($connection)) {
            SQLiteConnection::class => SQLiteGrammar::class,
            MySqlConnection::class => MySqlGrammar::class,
            PostgresConnection::class => PostgresGrammar::class,
            default => null,
        };
        $driver = match (get_class($connection)) {
            SQLiteConnection::class => 'sqlite',
            MySqlConnection::class => 'mysql',
            PostgresConnection::class => 'pgsql',
            default => null,
        };

        return $connection instanceof Connection && $connection->getDriverName() === $driver && $expected !== null && get_class($query->getGrammar()) === $expected && $query->getGrammar() === $connection->getQueryGrammar();
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return array<string,string>
     */
    private function projection(Builder $builder): array
    {
        $projection = [];
        foreach ($builder->getQuery()->columns ?? [] as $column) {
            if ($column instanceof \Illuminate\Contracts\Database\Query\Expression) {
                $column = $column->getValue($builder->getQuery()->getGrammar());
            }

            if (is_string($column) && preg_match('/\\A(.+)\\s+as\\s+([A-Za-z_][A-Za-z_0-9]*)\\z/iD', $column, $match)) {
                $projection[$match[2]] = $match[1];
            }
        }

        return $projection;
    }

    private function validateFields(ResolvedStoreOptions $options): void
    {
        $builder = $this->source;
        $query = $builder->getQuery();
        $connection = $query->getConnection();

        if (! $connection instanceof Connection) {
            throw DapterException::configuration();
        }
        $tables = [self::baseAlias($builder) => $builder->getModel()->getTable()];
        foreach ($query->joins ?? [] as $join) {
            if (! is_string($join->table) || ! preg_match('/\\A([A-Za-z_][A-Za-z_0-9]*)(?:\\s+(?:as\\s+)?([A-Za-z_][A-Za-z_0-9]*))?\\z/iD', $join->table, $match)) {
                throw DapterException::configuration();
            }
            $tables[$match[2] ?? $match[1]] = $match[1];
        }
        $columns = [];
        $schema = function (string $target) use ($connection, $tables, &$columns): ?array {
            $parts = explode('.', $target);
            $alias = count($parts) === 1 ? array_key_first($tables) : $parts[0];
            $name = count($parts) === 1 ? $parts[0] : ($parts[1] ?? '');

            if (count($parts) > 2 || ! isset($tables[$alias])) {
                return null;
            }

            if (! isset($columns[$alias])) {
                $columns[$alias] = array_column($connection->getSchemaBuilder()->getColumns($tables[$alias]), null, 'name');
            }

            return $columns[$alias][$name] ?? null;
        };
        $projection = $this->projection($builder);
        $validate = function (string $target, ?ValueType $type, bool $order = false) use ($schema, $projection): void {
            $original = $projection[$target] ?? $target;

            if (preg_match('/\\A(count|sum|min|max|avg)\\(([A-Za-z_][A-Za-z_0-9]*(?:\\.[A-Za-z_][A-Za-z_0-9]*)?|\\*)\\)\\z/iD', $original, $aggregate)) {
                if (! $order || $type === null || $type === ValueType::Boolean || $aggregate[2] !== '*' && $schema($aggregate[2]) === null) {
                    throw DapterException::configuration();
                }

                return;
            }
            $column = $schema($original);

            if ($column === null) {
                throw DapterException::configuration();
            }
            $native = strtolower($column['type_name']);
            $string = in_array($native, ['char', 'character', 'bpchar', 'varchar', 'character varying', 'text', 'tinytext', 'mediumtext', 'longtext', 'clob', 'enum'], true);
            $integer = in_array($native, ['int', 'integer', 'tinyint', 'smallint', 'mediumint', 'bigint', 'int2', 'int4', 'int8', 'serial', 'smallserial', 'bigserial'], true);
            $number = $integer || in_array($native, ['real', 'float', 'float4', 'float8', 'double', 'double precision', 'decimal', 'numeric'], true);
            $boolean = in_array($native, ['bool', 'boolean', 'tinyint'], true);
            $nativeOrder = $string || $number || $boolean || in_array($native, ['uuid', 'date', 'time', 'timetz', 'timestamp', 'timestamptz', 'datetime', 'year', 'interval'], true);

            if ($order && ! $nativeOrder || ! match ($type) {
                null => true, ValueType::String => $string, ValueType::Integer => $integer, ValueType::Number => $number, ValueType::Boolean => $boolean,
            }) {
                throw DapterException::configuration();
            }
        };
        foreach ($options->fields as $field) {
            if ($field->search !== null) {
                if (isset($projection[$field->target])) {
                    throw DapterException::configuration();
                }
                $validate($field->target, $field->search->valueType);
            }

            if ($field->order !== null) {
                $validate($field->target, $field->order->valueType, true);
            }
            foreach ($field->filters as $filter) {
                if (isset($projection[$field->target])) {
                    throw DapterException::configuration();
                }
                $validate($field->target, $filter->valueType);
            }
        }
        foreach ($options->global->search ?? [] as $target) {
            if (isset($projection[$target->target])) {
                throw DapterException::configuration();
            }
            $validate($target->target, $target->valueType);
        }
        foreach ($options->query->identity->columns ?? [] as $column) {
            $validate($column->target, $column->valueType, true);
            $selected = $projection[$column->output] ?? null;

            if ($selected !== null && $selected !== ($projection[$column->target] ?? $column->target) || $selected === null && ! $this->selects($column->target, $column->output)) {
                throw DapterException::configuration();
            }
        }
    }

    private function selects(string $target, string $output): bool
    {
        $columns = $this->source->getQuery()->columns;
        $plain = str_contains($target, '.') ? substr($target, strrpos($target, '.') + 1) : $target;

        if ($output !== $plain) {
            return false;
        }

        return $columns === null || in_array('*', $columns, true) || in_array(self::baseAlias($this->source).'.*', $columns, true) || in_array($target, $columns, true);
    }

    /** @param Builder<covariant Model> $builder */
    private static function advancedShape(Builder $builder, QueryOptions $options): bool
    {
        $query = $builder->getQuery();

        if (! self::supportedConnection($query) || ! is_string($query->from) || ! preg_match('/\\A[A-Za-z_][A-Za-z_0-9]*(?:\\s+(?:as\\s+)?[A-Za-z_][A-Za-z_0-9]*)?\\z/iD', $query->from) || $options->identity === null || $query->unions || $query->distinct || $query->limit !== null || $query->offset !== null || $query->aggregate !== null || $query->groupLimit !== null || $query->unionLimit !== null || $query->unionOffset !== null || $query->unionOrders || $query->beforeQueryCallbacks) {
            return false;
        }
        foreach ($query->groups ?? [] as $group) {
            if (! is_string($group) || ! preg_match('/\\A[A-Za-z_][A-Za-z_0-9]*(?:\\.[A-Za-z_][A-Za-z_0-9]*)?\\z/D', $group)) {
                return false;
            }
        }
        foreach ($query->columns ?? [] as $column) {
            if ($column instanceof \Illuminate\Contracts\Database\Query\Expression) {
                $column = $column->getValue($query->getGrammar());

                if (! is_string($column) || ! preg_match('/\\A(?:count|sum|min|max|avg)\\((?:[A-Za-z_][A-Za-z_0-9]*(?:\\.[A-Za-z_][A-Za-z_0-9]*)?|\\*)\\)\\s+as\\s+[A-Za-z_][A-Za-z_0-9]*\\z/iD', $column)) {
                    return false;
                }
            } elseif (! preg_match('/\\A(?:\\*|[A-Za-z_][A-Za-z_0-9]*(?:\\.(?:[A-Za-z_][A-Za-z_0-9]*|\\*))?)(?:\\s+as\\s+[A-Za-z_][A-Za-z_0-9]*)?\\z/iD', $column)) {
                return false;
            }
        }
        foreach ($query->orders ?? [] as $order) {
            if (($order['type'] ?? 'Basic') !== 'Basic' || ! is_string($order['column'] ?? null) || ! preg_match('/\\A[A-Za-z_][A-Za-z_0-9]*(?:\\.[A-Za-z_][A-Za-z_0-9]*)?\\z/D', $order['column']) || SortDirection::tryFrom($order['direction'] ?? '') === null) {
                return false;
            }
        }

        return true;
    }

    /** @param Builder<covariant Model> $scoped */
    private static function prepareOffset(Builder $scoped): bool
    {
        $query = $scoped->getQuery();
        $model = $scoped->getModel();
        $table = $model->getTable();
        $key = $model->getKeyName();

        if (! $query->getConnection() instanceof Connection || ! self::supportedConnection($query) || ! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\z/D', $table) || ! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\z/D', $key) || $query->from !== $table || $query->joins || $query->groups || $query->havings || $query->unions || $query->distinct || $query->limit !== null || $query->offset !== null || $query->aggregate !== null || $query->groupLimit !== null || $query->unionLimit !== null || $query->unionOffset !== null || $query->unionOrders || $query->beforeQueryCallbacks || ($query->columns !== null && $query->columns !== ['*'] && $query->columns !== [$table.'.*'])) {
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
