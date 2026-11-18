<?php

declare(strict_types=1);

use App\DataSources\ComposedFixture;
use App\DataSources\ComposedRecord;
use App\DataSources\ComposedSql;
use ArtisanToolbox\Dapter\Drivers\NegotiatedRead;
use ArtisanToolbox\Dapter\Drivers\RawPage;
use ArtisanToolbox\Dapter\Drivers\SqliteStoreDriver;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use ArtisanToolbox\Dapter\Options\CountQueries;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResultIdentity;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Assert;

require_once __DIR__.'/../Fixtures/ComposedReadHarness.php';

beforeEach(function (): void {
    ComposedFixture::$options = StoreOptions::make()->publicAccess()->pageSize(5)->pageSizeOptions([5, 10]);
    ComposedFixture::$builder = null;
    ComposedFixture::$reads = ComposedFixture::$constructed = ComposedFixture::$applied = ComposedFixture::$counts = 0;
});

afterEach(function (): void {
    $schema = DB::connection(ComposedFixture::$connection)->getSchemaBuilder();
    $schema->dropIfExists('dapter_composed_lines');
    $schema->dropIfExists('dapter_composed_records');
    ComposedFixture::$builder = null;
    ComposedFixture::$options = null;
    ComposedFixture::$rows = [];
});

/** @return Builder<ComposedRecord> */
function advancedBuilder(): Builder
{
    return (new ComposedRecord)->setConnection(ComposedFixture::$connection)->newQuery();
}

function advancedIdentity(): ResultIdentity
{
    return new ResultIdentity([new IdentityColumn('r.id', 'record_id', ValueType::Integer), new IdentityColumn('l.id', 'line_id', ValueType::Integer)]);
}

/** @return Builder<ComposedRecord> */
function advancedJoin(): Builder
{
    return advancedBuilder()->from('dapter_composed_records as r')->join('dapter_composed_lines as l', 'l.record_id', '=', 'r.id')->where('r.tenant', 1)->select(['r.id as record_id', 'l.id as line_id', 'r.name as label', 'l.amount']);
}

/** @return list<array{query:string,bindings:array<mixed>,time:float|null}> */
function advancedListingSql(): array
{
    return array_values(array_filter(DB::connection(ComposedFixture::$connection)->getQueryLog(), static fn (array $query): bool => preg_match('/\\bfrom\\s+["`]?dapter_composed_(?:records|lines)\\b/i', $query['query']) === 1));
}

it('supports qualified joined projections with complete result identities and exact logical counts', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$builder = advancedJoin();
    $sql = ComposedFixture::$builder->toSql();
    $bindings = ComposedFixture::$builder->getBindings();
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(advancedIdentity()))->searchableField('label', [MatchType::Equals], target: 'r.name', options: new FieldOptions('label'))->orderableField('amount', target: 'l.amount', valueType: ValueType::Integer, options: new FieldOptions('amount'));
    $response = $this->postJson('/dapter/data/ComposedSql', composedIntent(['label' => ['search' => ['value' => 'Alpha', 'matchType' => 'equals']]], order: ['kind' => 'fields', 'terms' => [['field' => 'amount', 'direction' => 'desc', 'sortType' => 'native']]]))->assertOk()->assertJsonPath('paging.total', 4)->assertJsonPath('paging.filtered', 3);
    expect(array_column($response->json('data'), 'line_id'))->toBe([12, 11, 31])->and(ComposedFixture::$builder->toSql())->toBe($sql)->and(ComposedFixture::$builder->getBindings())->toBe($bindings);
})->with(composedBackends(true));

it('counts grouped having results using native Laravel pagination counts and orders trusted aggregate aliases', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$builder = advancedBuilder()->from('dapter_composed_records as r')->join('dapter_composed_lines as l', 'l.record_id', '=', 'r.id')->where('r.tenant', 1)->select(['r.id as record_id', 'r.name'])->selectRaw('sum(l.amount) as total')->groupBy('r.id', 'r.name')->havingRaw('sum(l.amount) >= ?', [7]);
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('r.id', 'record_id', ValueType::Integer)])))->orderableField('total', valueType: ValueType::Number);
    $response = $this->postJson('/dapter/data/ComposedSql', composedIntent(order: ['kind' => 'fields', 'terms' => [['field' => 'total', 'direction' => 'desc', 'sortType' => 'native']]]))->assertOk()->assertJsonPath('paging.total', 2)->assertJsonPath('paging.filtered', 2);
    expect(array_column($response->json('data'), 'record_id'))->toBe([1, 2]);
})->with(composedBackends(true));

it('retains ordinary whereHas predicates and model identity without advanced opt in', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$builder = advancedBuilder()->where('tenant', 1)->whereHas('lines', static fn (Builder $query): Builder => $query->where('amount', '>=', 7));
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertOk()->assertJsonPath('paging.total', 1)->assertJsonPath('data.0.id', 2);
})->with(composedBackends(true));

it('invokes transforms and custom count factories once while keeping both mandatory count scopes', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->globalFilterable('quantity', [0, 2], static function (Builder $builder, int $value): Builder {
        ComposedFixture::$applied++;

        return $builder->where('quantity', $value);
    })->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)]), counts: static function (Builder $total, Builder $filtered): CountQueries {
        ComposedFixture::$counts++;

        return new CountQueries($total->reorder()->selectRaw('count(*) as aggregate'), $filtered->reorder()->selectRaw('count(*) as aggregate'));
    }));
    $this->postJson('/dapter/data/ComposedSql', composedIntent(global: ['filters' => ['quantity' => ['value' => 0]]]))->assertOk()->assertJsonPath('paging.total', 6)->assertJsonPath('paging.filtered', 2);
    expect(ComposedFixture::$counts)->toBe(1)->and(ComposedFixture::$applied)->toBe(1);
})->with(composedBackends(true));

it('rejects a same model fresh builder that loses the mandatory predicate before listing SQL', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->globalFilterable('status', ['active'], static fn (Builder $builder, string $value): Builder => $builder->getModel()->newQuery()->where('status', $value));
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', composedIntent(global: ['filters' => ['status' => ['value' => 'active']]]))->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
})->with(composedBackends(true));

it('rejects widened custom filtered counters before either aggregate query executes', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->searchableField('name', [MatchType::Equals])->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)]), counts: static function (Builder $total, Builder $filtered): CountQueries {
        return new CountQueries($total->reorder()->selectRaw('count(*) as aggregate'), $total->reorder()->selectRaw('count(*) as aggregate'));
    }));
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', composedIntent(['name' => ['search' => ['value' => 'Alpha', 'matchType' => 'equals']]]))->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
})->with(composedBackends(true));

it('rejects duplicate identities across the complete result instead of dropping joined rows', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$builder = advancedJoin();
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('r.id', 'record_id', ValueType::Integer)])));
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertStatus(500);
})->with(composedBackends(true));

it('rejects unsupported projections and ordinary advanced shapes before listing SQL', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$builder = advancedJoin();
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(advancedIdentity()));
    ComposedFixture::$builder = advancedJoin()->selectRaw('l.amount + 1 as computed');
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
})->with(composedBackends(true));

it('rejects aggregate aliases as automatic search predicates before listing SQL', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$builder = advancedBuilder()->where('tenant', 1)->select('status')->selectRaw('count(*) as total')->groupBy('status');
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('status', 'status', ValueType::String)])))->searchableField('total', [MatchType::Equals], valueType: ValueType::Integer);
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
})->with(composedBackends(true));

it('checks full result identities with bounded SQL probes and hydrates only the requested page', function (string $backend): void {
    composedPrepare($backend);
    $extra = [];
    foreach (range(10, 109) as $id) {
        $extra[] = ['id' => $id, 'tenant' => 1, 'name' => 'Record '.$id, 'quantity' => 0, 'status' => 'active', 'rank' => 1];
    }
    DB::connection(ComposedFixture::$connection)->table('dapter_composed_records')->insert($extra);
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)])));
    $retrieved = 0;
    Event::listen('eloquent.retrieved: '.ComposedRecord::class, static function () use (&$retrieved): void {
        $retrieved++;
    });
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('paging.total', 106);
    expect($retrieved)->toBe(5);
    $probes = array_filter(advancedListingSql(), static fn (array $query): bool => str_contains(strtolower($query['query']), 'exists(') || str_contains(strtolower($query['query']), 'exists ('));
    expect($probes)->not->toBeEmpty();
    foreach ($probes as $probe) {
        expect(strtolower($probe['query']))->toContain('limit 1');
    }
})->with(composedBackends(true));

it('rejects clone callbacks that lose mandatory predicates before listing SQL', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$builder = advancedBuilder()->where('tenant', 1)->onClone(static function (Builder $clone): void {
        $clone->getQuery()->wheres = [];
        $clone->getQuery()->setBindings([], 'where');
    });
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
})->with(composedBackends(true));

it('rejects top level OR widening after a transform before listing SQL', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->globalFilterable('status', ['active'], static fn (Builder $builder, string $value): Builder => $builder->orWhere('status', $value));
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', composedIntent(global: ['filters' => ['status' => ['value' => 'active']]]))->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
})->with(composedBackends(true));

it('uses plan query options for direct drivers and negotiated drivers without replacing their source', function (): void {
    composedPrepare('sqlite');
    $builder = advancedJoin();
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(advancedIdentity()));
    $options = (new OptionsResolver)->resolve(new ComposedSql);
    $paging = new OffsetRequest(1, 5);
    $plan = new ValidatedReadPlan($options, $paging);
    $driver = new SqliteStoreDriver($builder);
    expect($driver->capabilities()->offset)->toBeFalse();
    $page = $driver->execute($plan);
    expect($page->total)->toBe(4)->and(array_map(static fn (ComposedRecord $record): int => $record->getAttribute('line_id'), $page->rows))->toBe([11, 12, 21, 31]);
    $negotiated = NegotiatedRead::fromDriver($driver, $options, $paging);
    $negotiatedPage = $negotiated->execute();
    Assert::assertInstanceOf(RawPage::class, $negotiatedPage);
    expect($negotiatedPage->total)->toBe(4);
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)])));
    $hinted = new SqliteStoreDriver(advancedBuilder()->where('tenant', 1), new QueryOptions(advancedIdentity()));
    $plain = (new OptionsResolver)->resolve(new ComposedSql);
    expect($hinted->execute(new ValidatedReadPlan($plain, $paging))->total)->toBe(6);
});

it('rejects identities duplicated only beyond the first page without hydrating those records', function (string $backend): void {
    composedPrepare($backend);
    $rows = [];
    foreach (range(10, 16) as $id) {
        $rows[] = ['id' => $id, 'tenant' => 1, 'name' => 'Later '.$id, 'quantity' => $id === 16 ? 15 : $id, 'status' => 'active', 'rank' => 1];
    }
    DB::connection(ComposedFixture::$connection)->table('dapter_composed_records')->insert($rows);
    ComposedFixture::$builder = advancedBuilder()->where('tenant', 1)->where('id', '>=', 10);
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('quantity', 'quantity', ValueType::Integer)])));
    $retrieved = 0;
    Event::listen('eloquent.retrieved: '.ComposedRecord::class, static function () use (&$retrieved): void {
        $retrieved++;
    });
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertStatus(500);
    expect($retrieved)->toBe(0);
})->with(composedBackends(true));

it('rejects changed mandatory SQL binding buckets before listing queries', function (string $backend, string $bucket): void {
    composedPrepare($backend);

    if ($bucket === 'having') {
        ComposedFixture::$builder = advancedBuilder()->from('dapter_composed_records as r')->join('dapter_composed_lines as l', 'l.record_id', '=', 'r.id')->where('r.tenant', 1)->select(['r.id as record_id', 'r.name'])->selectRaw('sum(l.amount) as total')->groupBy('r.id', 'r.name')->havingRaw('sum(l.amount) >= ?', [7]);
        $identity = new ResultIdentity([new IdentityColumn('r.id', 'record_id', ValueType::Integer)]);
    } elseif ($bucket === 'join') {
        ComposedFixture::$builder = advancedBuilder()->from('dapter_composed_records as r')->join('dapter_composed_lines as l', static function (JoinClause $join): void {
            $join->on('l.record_id', '=', 'r.id')->where('l.amount', '>=', 7);
        })->where('r.tenant', 1)->select(['r.id as record_id', 'l.id as line_id', 'l.amount']);
        $identity = advancedIdentity();
    } else {
        ComposedFixture::$builder = advancedBuilder()->where('tenant', 1);
        $identity = new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)]);
    }
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions($identity))->globalFilterable('show', [1], static function (Builder $builder, int $value) use ($bucket): Builder {
        $builder->getQuery()->setBindings([$bucket === 'where' ? 2 : 0], match ($bucket) {
            'where' => 'where', 'having' => 'having', 'join' => 'join', default => throw new InvalidArgumentException('Unknown binding bucket.'),
        });

        return $builder->where($bucket === 'where' ? 'id' : 'r.id', $bucket === 'where' ? 9 : 3);
    });
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', composedIntent(global: ['filters' => ['show' => ['value' => 1]]]))->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
})->with(composedBackends(true))->with(['where', 'having', 'join']);

it('rejects mandatory binding changes made by global scopes before listing queries', function (string $backend): void {
    composedPrepare($backend);
    ComposedFixture::$builder = advancedBuilder()->where('tenant', 1)->withGlobalScope('tampered-bindings', static function (Builder $builder): void {
        $builder->getQuery()->setBindings([2], 'where');
    });
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
})->with(composedBackends(true));

it('rejects model grammar and PDO route changes made by scopes before listing queries', function (string $change): void {
    composedPrepare('sqlite');
    $replacement = new PDO('sqlite::memory:');
    $replacement->exec('create table dapter_composed_records (id integer primary key, tenant integer, name text)');
    $replacement->exec("insert into dapter_composed_records (id, tenant, name) values (99, 1, 'Other database')");
    ComposedFixture::$builder = advancedBuilder()->where('tenant', 1)->withGlobalScope('tampered-route', static function (Builder $builder) use ($change, $replacement): void {
        $connection = $builder->getQuery()->getConnection();

        throw_unless($connection instanceof SQLiteConnection, LogicException::class, 'This fixture requires SQLite.');

        if ($change === 'write') {
            $connection->setPdo($replacement);
        } elseif ($change === 'read') {
            $connection->setReadPdo($replacement);
        } elseif ($change === 'model') {
            $builder->getModel()->setKeyName('tenant');
        } else {
            $grammar = new SQLiteGrammar($connection);
            $connection->setQueryGrammar($grammar);
            $builder->getQuery()->grammar = $grammar;
        }
    });
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertStatus(500);
    expect(advancedListingSql())->toBe([]);
})->with(['write', 'read', 'model', 'grammar']);

it('retains the original query shape before accepting global scope changes', function (string $backend, string $part): void {
    composedPrepare($backend);
    $builder = advancedBuilder()->where('tenant', 1)->select(['id', 'name']);

    if ($part === 'joins') {
        $builder->join('dapter_composed_lines as l', static function (JoinClause $join): void {
            $join->on('l.record_id', '=', 'dapter_composed_records.id')->whereRaw('l.amount >= 7');
        })->select('dapter_composed_records.*');
    } elseif ($part === 'limit') {
        $builder->limit(1);
    } elseif ($part === 'callbacks') {
        $builder->getQuery()->beforeQuery(static function (): void {
            ComposedFixture::$applied++;
        });
    } elseif (in_array($part, ['groups', 'havings'], true)) {
        $builder->groupBy('id', 'name');

        if ($part === 'havings') {
            $builder->havingRaw('count(*) >= 2');
        }
    }
    $builder->withGlobalScope('changed-shape', static function (Builder $scoped) use ($part): void {
        $query = $scoped->getQuery();
        match ($part) {
            'from' => $scoped->from('dapter_composed_records as changed'),
            'columns' => $scoped->select('id'),
            'joins' => $query->joins = null,
            'groups' => $query->groups = null,
            'havings' => $query->havings = null,
            'limit' => $query->limit = null,
            'callbacks' => $query->beforeQueryCallbacks = [],
            default => throw new InvalidArgumentException('Unknown query shape.'),
        };
    });
    ComposedFixture::$builder = $builder;
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)])));
    $sql = $part === 'callbacks' ? null : $builder->getQuery()->toSql();
    $bindings = $builder->getQuery()->getBindings();
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    foreach ([1, 2] as $attempt) {
        $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertStatus(500);
        expect(ComposedFixture::$applied)->toBe(0);
        expect(advancedListingSql())->toBe([])->and($part === 'callbacks' ? null : $builder->getQuery()->toSql())->toBe($sql)->and($builder->getQuery()->getBindings())->toBe($bindings);
    }
})->with(composedBackends(true))->with(['from', 'columns', 'joins', 'groups', 'havings', 'limit', 'callbacks']);

it('allows global scopes to append mandatory query structure and AND constraints without changing the caller query', function (string $backend): void {
    composedPrepare($backend);
    $builder = advancedBuilder()->where('dapter_composed_records.tenant', 1)->select('dapter_composed_records.id')->groupBy('dapter_composed_records.id')->havingRaw('count(*) >= 1')->withGlobalScope('narrow-shape', static function (Builder $scoped): void {
        ComposedFixture::$applied++;
        $scoped->join('dapter_composed_lines as l', static function (JoinClause $join): void {
            $join->on('l.record_id', '=', 'dapter_composed_records.id')->where('l.amount', '>=', 7);
        })->addSelect('dapter_composed_records.name')->groupBy('dapter_composed_records.name')->where('dapter_composed_records.status', 'active')->havingRaw('count(*) <= 2');
    });
    ComposedFixture::$builder = $builder;
    ComposedFixture::$options = ComposedFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('dapter_composed_records.id', 'id', ValueType::Integer)])));
    $sql = $builder->getQuery()->toSql();
    $bindings = $builder->getQuery()->getBindings();
    foreach ([1, 2] as $attempt) {
        $this->postJson('/dapter/data/ComposedSql', ['version' => 2])->assertOk()->assertJsonPath('paging.total', 1)->assertJsonPath('data.0.id', 2);
        expect(ComposedFixture::$applied)->toBe($attempt)->and($builder->getQuery()->toSql())->toBe($sql)->and($builder->getQuery()->getBindings())->toBe($bindings);
    }
})->with(composedBackends(true));
