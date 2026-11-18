<?php

declare(strict_types=1);

use App\DataSources\FixtureRecord;
use App\DataSources\OptionRecords;
use App\DataSources\SqlRecords;
use ArtisanToolbox\Dapter\Drivers\DriverFactory;
use ArtisanToolbox\Dapter\Drivers\EloquentStoreDriver;
use ArtisanToolbox\Dapter\Drivers\NegotiatedRead;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Fixtures/ListingSources.php';

beforeEach(function () {
    FixtureRecord::clearBootedModels();
    SqlRecords::$builder = null;
    OptionRecords::$reads = 0;
    Schema::create('fixture_records', function (Blueprint $table): void {
        $table->id();
        $table->integer('tenant');
        $table->string('name');
        $table->string('secret');
    });
    FixtureRecord::query()->insert([
        ['id' => 8, 'tenant' => 1, 'name' => 'Notebook', 'secret' => 'hidden'],
        ['id' => 2, 'tenant' => 1, 'name' => 'Pencil', 'secret' => 'hidden'],
        ['id' => 5, 'tenant' => 2, 'name' => 'Outside', 'secret' => 'hidden'],
    ]);
});

it('uses an ordinary model primary key order and normal visible serialization', function () {
    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertOk()
        ->assertJsonPath('data.0.id', 2)->assertJsonPath('data.1.id', 5)->assertJsonPath('data.2.id', 8)
        ->assertJsonMissingPath('data.0.secret')->assertJsonPath('paging.total', 3)->assertJsonPath('state.order', ['kind' => 'source']);
    expect(OptionRecords::$reads)->toBe(1);
});

it('applies scopes exactly once preserves tenant boundaries and never mutates the supplied builder', function () {
    $calls = 0;
    FixtureRecord::addGlobalScope('tenant', function (Builder $builder) use (&$calls): void {
        $calls++;
        $builder->where('tenant', 1)->where('name', '!=', 'Outside');
    });
    $query = FixtureRecord::query()->orderBy('name', 'desc');
    $original = clone $query->getQuery();
    SqlRecords::$builder = $query;
    DB::enableQueryLog();
    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertOk()
        ->assertJsonPath('data.0.name', 'Pencil')->assertJsonPath('data.1.name', 'Notebook')
        ->assertJsonCount(2, 'data')->assertJsonPath('paging.total', 2)->assertJsonPath('paging.filtered', 2);
    $queries = DB::getQueryLog();
    expect($calls)->toBe(1)->and($query->getQuery()->toSql())->toBe($original->toSql())
        ->and($query->getQuery()->getBindings())->toBe($original->getBindings())
        ->and($query->getQuery()->limit)->toBeNull()->and($query->getQuery()->offset)->toBeNull()
        ->and(array_filter($queries, static fn (array $query): bool => str_contains($query['query'], 'count(')))->toHaveCount(1);
    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertOk();
    expect($calls)->toBe(2);
});

it('preserves top-level OR predicates and completes tied source order with the primary key', function () {
    FixtureRecord::query()->whereKey(8)->update(['name' => 'Pencil']);
    SqlRecords::$builder = FixtureRecord::query()->where('id', 8)->orWhere('id', 2)->orderBy('name');
    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertOk()->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', 2)->assertJsonPath('data.1.id', 8)->assertJsonPath('paging.total', 2);
});

it('binds one scoped snapshot and negotiates its profile without issuing listing SQL', function () {
    $calls = 0;
    FixtureRecord::addGlobalScope('tenant', function (Builder $builder) use (&$calls): void {
        $calls++;
        $builder->where('tenant', 1);
    });
    $query = FixtureRecord::query()->orderBy('name');
    SqlRecords::$builder = $query;
    $original = clone $query->getQuery();
    DB::enableQueryLog();
    $source = new SqlRecords;
    $options = (new OptionsResolver)->resolve($source);
    $driver = (new DriverFactory)->make($source);
    $profile = $driver->capabilities();
    $read = NegotiatedRead::fromDriver($driver, $options, new OffsetRequest(1, 5));

    expect($profile->offset)->toBeTrue()->and($driver->capabilities())->toBe($profile)
        ->and($read->wireCapabilities()['paging'])->toBe(['mode' => 'offset', 'per_page' => 5, 'per_page_options' => [5, 10, 50, 100]])
        ->and($calls)->toBe(1)->and(OptionRecords::$reads)->toBe(1)->and(DB::getQueryLog())->toBe([])
        ->and($query->getQuery()->toSql())->toBe($original->toSql())->and($query->getQuery()->getBindings())->toBe($original->getBindings());

    $query->where('id', 5)->limit(1);
    expect(array_column($read->execute()->rows, 'id'))->toBe([8, 2])
        ->and($driver->execute(new ValidatedReadPlan($options, new OffsetRequest(1, 5)))->total)->toBe(2)
        ->and($calls)->toBe(1)
        ->and(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'count(')))->toHaveCount(2);
});

it('rejects scope-added windows without executing count or page reads', function () {
    FixtureRecord::addGlobalScope('window', static fn (Builder $builder): Builder => $builder->limit(1));
    DB::enableQueryLog();
    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertStatus(500)->assertJsonPath('error.kind', 'server');
    expect(DB::getQueryLog())->toBe([]);
});

it('rejects deferred query callbacks without invoking them or executing listing SQL', function () {
    $calls = 0;
    $query = FixtureRecord::query();
    $query->getQuery()->beforeQuery(static function (QueryBuilder $query) use (&$calls): void {
        $calls++;
        $query->limit(1);
    });
    SqlRecords::$builder = $query;
    DB::enableQueryLog();

    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertStatus(500)
        ->assertExactJson(['version' => 1, 'error' => ['kind' => 'server', 'code' => 'invalid_source', 'message' => 'The data request could not be completed.']]);

    expect((new EloquentStoreDriver($query))->capabilities()->offset)->toBeFalse()
        ->and($calls)->toBe(0)->and(DB::getQueryLog())->toBe([])
        ->and($query->getQuery()->beforeQueryCallbacks)->toHaveCount(1)
        ->and($query->getQuery()->orders)->toBeNull()
        ->and($query->getQuery()->limit)->toBeNull()->and($query->getQuery()->offset)->toBeNull();
});

it('rejects result callbacks that change the bounded page cardinality', function (string $change) {
    $query = FixtureRecord::query()->afterQuery(static fn (Collection $rows): Collection => $change === 'drop' ? $rows->take(1) : $rows->concat($rows));
    SqlRecords::$builder = $query;
    DB::enableQueryLog();

    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertStatus(500)
        ->assertExactJson(['version' => 1, 'error' => ['kind' => 'server', 'code' => 'invalid_source', 'message' => 'The data request could not be completed.']]);

    expect((new EloquentStoreDriver($query))->capabilities()->offset)->toBeTrue()
        ->and($query->getQuery()->orders)->toBeNull()
        ->and($query->getQuery()->limit)->toBeNull()->and($query->getQuery()->offset)->toBeNull()
        ->and(DB::getQueryLog())->toHaveCount(2);
})->with(['drop', 'duplicate']);

it('preserves result callbacks that project visible attributes without changing page counts', function () {
    $query = FixtureRecord::query()->afterQuery(static fn (Collection $rows): Collection => $rows->each(static fn (Model $row): Model => $row->setVisible(['name'])));
    SqlRecords::$builder = $query;
    DB::enableQueryLog();

    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertOk()
        ->assertJsonPath('data', [['name' => 'Pencil'], ['name' => 'Outside'], ['name' => 'Notebook']])
        ->assertJsonPath('paging.total', 3)->assertJsonPath('paging.filtered', 3)->assertJsonPath('paging.pages', 1);

    expect($query->getQuery()->orders)->toBeNull()
        ->and($query->getQuery()->limit)->toBeNull()->and($query->getQuery()->offset)->toBeNull()
        ->and(DB::getQueryLog())->toHaveCount(2);
});

it('rejects a negative native count before reading page rows', function () {
    $query = FixtureRecord::query()->whereKey(999);
    $query->getQuery()->afterQuery(static function (SupportCollection $rows): SupportCollection {
        foreach ($rows as $row) {
            if (isset($row->aggregate)) {
                $row->aggregate = -1;
            }
        }

        return $rows;
    });
    SqlRecords::$builder = $query;
    DB::enableQueryLog();

    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertStatus(500)
        ->assertExactJson(['version' => 1, 'error' => ['kind' => 'server', 'code' => 'invalid_source', 'message' => 'The data request could not be completed.']]);

    expect(DB::getQueryLog())->toHaveCount(1);
});

it('rejects complex queries and other SQL dialects without executing them', function (string $shape) {
    $query = FixtureRecord::query();
    match ($shape) {
        'limit' => $query->limit(1),
        'offset' => $query->offset(1),
        'groupLimit' => $query->groupLimit(1, 'tenant'),
        'join' => $query->join('other', 'other.id', '=', 'fixture_records.id'),
        'group' => $query->groupBy('name'),
        'having' => $query->having('id', '>', 1),
        'union' => $query->union(FixtureRecord::query()),
        'distinct' => $query->distinct(),
        'projection' => $query->select('id'),
        'expression' => $query->selectRaw('id as id'),
        'rawOrder' => $query->orderByRaw('name desc'),
        'alias' => $query->from('fixture_records as records'),
        default => null,
    };

    if ($shape === 'dialect') {
        config()->set('database.connections.unsupported', ['driver' => 'sqlsrv', 'host' => '127.0.0.1', 'database' => 'unused']);
        $query = FixtureRecord::on('unsupported');
    }
    SqlRecords::$builder = $query;
    DB::enableQueryLog();
    $driver = new EloquentStoreDriver($query);
    expect($driver->capabilities()->offset)->toBeFalse();
    $options = (new OptionsResolver)->resolve(new SqlRecords);
    expect(fn () => $driver->execute(new ValidatedReadPlan($options, new OffsetRequest(1, 5))))->toThrow(DapterException::class)
        ->and(fn () => NegotiatedRead::fromDriver($driver, $options, new OffsetRequest(1, 5)))->toThrow(DapterException::class);
    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertStatus(500)->assertJsonPath('error.kind', 'server')->assertJsonMissingPath('data');
    expect(DB::getQueryLog())->toBe([]);
})->with(['limit', 'offset', 'groupLimit', 'join', 'group', 'having', 'union', 'distinct', 'projection', 'expression', 'rawOrder', 'alias', 'dialect']);

it('uses one count and bounded offset page for larger ordinary sources', function () {
    FixtureRecord::query()->delete();
    foreach (range(1, 12) as $id) {
        FixtureRecord::query()->create(['id' => $id, 'tenant' => 1, 'name' => 'Record '.$id, 'secret' => 'hidden']);
    }
    $state = json_decode('{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":999,"per_page":5}}', flags: JSON_THROW_ON_ERROR);
    $this->postJson('/dapter/data/SqlRecords', ['version' => 1, 'state' => $state])->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', 11)->assertJsonPath('paging.current', 3)->assertJsonPath('requested.paging.current', 3)->assertJsonPath('paging.total', 12);
});

it('rejects an unextractable ordinary model identity rather than inventing one', function () {
    Schema::drop('fixture_records');
    Schema::create('fixture_records', static function (Blueprint $table): void {
        $table->integer('id')->nullable();
        $table->string('name');
    });
    DB::table('fixture_records')->insert(['id' => null, 'name' => 'Missing identity']);
    $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertStatus(500)->assertJsonPath('error.kind', 'server')->assertJsonMissingPath('data');
});

it('does not call an Eloquent source or perform listing SQL before full intent validation', function () {
    $calls = 0;
    FixtureRecord::addGlobalScope('tenant', function (Builder $builder) use (&$calls): void {
        $calls++;
        $builder->where('tenant', 1);
    });
    DB::enableQueryLog();
    $state = json_decode('{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":1,"per_page":7}}', flags: JSON_THROW_ON_ERROR);
    $this->postJson('/dapter/data/SqlRecords', ['version' => 1, 'state' => $state])->assertStatus(422)->assertJsonPath('errors._dapter_kind', ['validation']);
    expect(OptionRecords::$reads)->toBe(0)->and($calls)->toBe(0)->and(DB::getQueryLog())->toBe([]);
});

it('rejects a stale connection grammar binding before catalog count or page reads', function (): void {
    $source = FixtureRecord::query();
    $driver = new EloquentStoreDriver($source);
    $options = (new OptionsResolver)->resolve(new SqlRecords);
    $connection = $source->getQuery()->getConnection();

    throw_unless($connection instanceof Connection, RuntimeException::class, 'The fixture requires a native Laravel connection.');
    $original = $connection->getQueryGrammar();
    DB::enableQueryLog();

    try {
        $connection->setQueryGrammar(new MySqlGrammar($connection));
        expect($driver->capabilities()->offset)->toBeTrue()
            ->and(fn () => $driver->execute(new ValidatedReadPlan($options, new OffsetRequest(1, 5))))->toThrow(DapterException::class)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        $connection->setQueryGrammar($original);
    }
});

it('rejects a retargeted connection identity before performing catalog count or page reads', function (): void {
    $source = FixtureRecord::query();
    $driver = new EloquentStoreDriver($source);
    $options = (new OptionsResolver)->resolve(new SqlRecords);
    $connection = $source->getQuery()->getConnection();

    throw_unless($connection instanceof Connection, RuntimeException::class, 'The fixture requires a native Laravel connection.');
    $database = $connection->getDatabaseName();
    DB::enableQueryLog();

    try {
        $connection->setDatabaseName('retargeted_fixture');
        expect(fn () => $driver->execute(new ValidatedReadPlan($options, new OffsetRequest(1, 5))))->toThrow(DapterException::class)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        $connection->setDatabaseName($database);
    }
});

it('rejects replacement database handles before catalog count or page reads', function (string $handle): void {
    $source = FixtureRecord::query();
    $driver = new EloquentStoreDriver($source);
    $options = (new OptionsResolver)->resolve(new SqlRecords);
    $connection = $source->getQuery()->getConnection();

    throw_unless($connection instanceof Connection, RuntimeException::class, 'The fixture requires a native Laravel connection.');
    $pdo = $handle === 'write' ? $connection->getPdo() : $connection->getReadPdo();
    DB::enableQueryLog();

    try {
        if ($handle === 'write') {
            $connection->setPdo(new PDO('sqlite::memory:'));
        } else {
            $connection->setReadPdo(new PDO('sqlite::memory:'));
        }
        expect(fn () => $driver->execute(new ValidatedReadPlan($options, new OffsetRequest(1, 5))))->toThrow(DapterException::class)
            ->and(DB::getQueryLog())->toBe([]);
    } finally {
        if ($handle === 'write') {
            $connection->setPdo($pdo);
        } else {
            $connection->setReadPdo($pdo);
        }
    }
})->with(['write', 'read']);
