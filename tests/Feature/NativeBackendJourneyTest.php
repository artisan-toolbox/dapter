<?php

declare(strict_types=1);

use App\DataSources\NativeBackendFixture;
use App\DataSources\NativeBackendRecord;
use ArtisanToolbox\Dapter\Broadcasting\CommitBoundary;
use ArtisanToolbox\Dapter\Contracts\RowIdentityProvider;
use ArtisanToolbox\Dapter\Drivers\EloquentStoreDriver;
use ArtisanToolbox\Dapter\Drivers\SqlIdentitySelector;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Fixtures/NativeBackendSources.php';

/** @return array<string, array{string}> */
function nativeSqlBackends(): array
{
    $backends = ['SQLite' => ['sqlite']];

    if (getenv('DAPTER_NATIVE_DATABASES') === '1') {
        $backends['MySQL'] = ['mysql'];
        $backends['PostgreSQL'] = ['pgsql'];
    }

    return $backends;
}

dataset('native backend sources', fn (): array => [
    'Array' => ['array'],
    'Collection' => ['collection'],
    ...nativeSqlBackends(),
]);

dataset('native SQL sources', fn (): array => nativeSqlBackends());

/** @param array<string, mixed> $fields
 * @param  array<string, mixed>|null  $order
 * @return array<string, mixed>
 */
function nativeBackendIntent(array $fields = [], ?array $order = null, int $page = 1): array
{
    return [
        'version' => 2,
        'fields' => (object) $fields,
        'global' => (object) [],
        'searchMatch' => 'all',
        'order' => $order ?? ['kind' => 'default'],
        'paging' => ['mode' => 'offset', 'current' => $page, 'per_page' => 5],
    ];
}

function nativeBackendEnvironment(string $name, string $default = ''): string
{
    $value = getenv($name);

    return $value === false ? $default : $value;
}

function prepareNativeBackend(string $backend, ?string $unicodeCollation = null): string
{
    NativeBackendFixture::$rows = NativeBackendFixture::records();

    if ($backend === 'array' || $backend === 'collection') {
        return $backend === 'array' ? 'NativeArrayRecords' : 'NativeCollectionRecords';
    }

    $connection = $backend === 'sqlite' ? 'testing' : 'dapter_native_'.$backend;
    NativeBackendFixture::$connection = $connection;

    if ($backend !== 'sqlite') {
        $prefix = $backend === 'mysql' ? 'DAPTER_MYSQL_' : 'DAPTER_PGSQL_';
        $database = nativeBackendEnvironment($prefix.'DATABASE');

        throw_if(! preg_match('/\Adapter_[a-zA-Z0-9_]+\z/D', $database), RuntimeException::class,
            'Native backend verification requires an isolated dapter_ database in '.$prefix.'DATABASE.');

        config()->set('database.connections.'.$connection, [
            'driver' => $backend,
            'host' => nativeBackendEnvironment($prefix.'HOST', '127.0.0.1'),
            'port' => nativeBackendEnvironment($prefix.'PORT', $backend === 'mysql' ? '3306' : '5432'),
            'database' => $database,
            'username' => nativeBackendEnvironment($prefix.'USERNAME', $backend === 'mysql' ? 'root' : 'postgres'),
            'password' => nativeBackendEnvironment($prefix.'PASSWORD'),
            'charset' => $backend === 'mysql' ? 'utf8mb4' : 'utf8',
            'collation' => $backend === 'mysql' ? 'utf8mb4_bin' : null,
            'prefix' => '',
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    $schema = DB::connection($connection)->getSchemaBuilder();
    $schema->dropIfExists('dapter_native_records');
    $schema->create('dapter_native_records', static function (Blueprint $table) use ($backend, $unicodeCollation): void {
        $table->id();
        $table->integer('tenant');
        $table->string('name')->collation($backend === 'pgsql' ? 'C' : ($backend === 'mysql' ? 'utf8mb4_bin' : 'BINARY'));
        $table->string('unicode_name')->collation($unicodeCollation ?? ($backend === 'pgsql' ? 'C' : ($backend === 'mysql' ? 'utf8mb4_bin' : 'BINARY')));
        $table->integer('rank');
        $table->integer('quantity')->nullable();
        $table->string('code')->nullable()->collation($backend === 'pgsql' ? 'C' : ($backend === 'mysql' ? 'utf8mb4_bin' : 'BINARY'));
        $table->boolean('enabled')->nullable();
        $table->string('status')->nullable()->collation($backend === 'pgsql' ? 'C' : ($backend === 'mysql' ? 'utf8mb4_bin' : 'BINARY'));
        $table->string('secret');
    });
    NativeBackendRecord::on($connection)->insert(NativeBackendFixture::$rows);
    NativeBackendRecord::addGlobalScope('tenant', static fn (Builder $query): Builder => $query->where('tenant', 1));
    DB::connection($connection)->enableQueryLog();
    DB::connection($connection)->flushQueryLog();

    return 'NativeSqlRecords';
}

beforeEach(function (): void {
    NativeBackendRecord::clearBootedModels();
    NativeBackendFixture::$reads = 0;
    NativeBackendFixture::$connection = 'testing';
    NativeBackendFixture::$managed = true;
    NativeBackendFixture::$settings = null;
    NativeBackendFixture::$builder = null;
    NativeBackendFixture::$rows = [];
});

afterEach(function (): void {
    DB::connection(NativeBackendFixture::$connection)->getSchemaBuilder()->dropIfExists('dapter_native_records');
    NativeBackendRecord::clearBootedModels();
    NativeBackendFixture::$settings = null;
    NativeBackendFixture::$builder = null;
    NativeBackendFixture::$rows = [];
});

it('keeps base counts when an accepted operation matches no rows', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    $intent = nativeBackendIntent(['label' => ['search' => ['value' => 'Missing record', 'matchType' => 'equals']]], page: 99);

    $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('paging.total', 100)->assertJsonPath('paging.filtered', 0)
        ->assertJsonPath('paging.current', 1)->assertJsonPath('paging.pages', 1)
        ->assertJsonPath('paging.has_previous', false)->assertJsonPath('paging.has_next', false)
        ->assertJsonPath('metadata', ['records' => ['mode' => 'unidentified'], 'channel' => null])
        ->assertJsonPath('requested.fields.label.search', ['value' => 'Missing record', 'matchType' => 'equals']);
})->with('native backend sources');

it('treats SQL wildcard and escape characters as literal search values', function (string $backend): void {
    $source = prepareNativeBackend($backend);

    foreach (['%', '_', '\\', '!', '%_\\!'] as $value) {
        $intent = nativeBackendIntent(['label' => ['search' => ['value' => $value, 'matchType' => 'contains']]]);
        $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1)
            ->assertJsonPath('paging.total', 100)->assertJsonPath('paging.filtered', 1);
    }

    foreach ([['Tools %_\\! kit', 'equals'], ['Tools %', 'starts_with'], ['\\! kit', 'ends_with']] as [$value, $matchType]) {
        $intent = nativeBackendIntent(['label' => ['search' => ['value' => $value, 'matchType' => $matchType]]]);
        $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1)->assertJsonPath('paging.filtered', 1);
    }
})->with('native backend sources');

it('reports the native null placement without imposing cross-engine order', function (string $backend): void {
    $source = prepareNativeBackend($backend);

    foreach (['asc', 'desc'] as $direction) {
        $order = ['kind' => 'fields', 'terms' => [['field' => 'quantity', 'direction' => $direction, 'sortType' => 'native']]];
        $expected = match (true) {
            $backend === 'pgsql' && $direction === 'asc' => [1, 3, 4, 5, 6],
            $backend === 'pgsql' => [2, 3, 4, 5, 6],
            $direction === 'asc' => [2, 1, 3, 4, 5],
            default => [3, 4, 5, 6, 7],
        };
        $response = $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => nativeBackendIntent(order: $order)])->assertOk();
        expect(array_column($response->json('data'), 'id'))->toBe($expected);
    }
})->with('native backend sources');

it('preserves typed zero false and null selections through the HTTP boundary', function (string $backend): void {
    $source = prepareNativeBackend($backend);

    foreach ([['quantity', 0, 1], ['code', '0', 1], ['enabled', false, 1], ['quantity', null, 2], ['code', null, 2], ['enabled', null, 2]] as [$field, $value, $id]) {
        $intent = nativeBackendIntent([$field => ['filters' => [$field => ['value' => $value]]]]);
        $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('paging.total', 100)->assertJsonPath('paging.filtered', 1)
            ->assertJsonPath('requested.fields.'.$field.'.filters.'.$field.'.value', $value);
    }
})->with('native backend sources');

it('keeps case accents and Unicode composition distinct under the declared comparison', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    NativeBackendFixture::$settings = NativeBackendFixture::options()->strictLiteralSearch();

    foreach ([['Café', 1], ["Cafe\u{0301}", 2], ['CAFÉ', 3], ['cafe', 4]] as [$value, $id]) {
        $intent = nativeBackendIntent(['unicode_name' => ['search' => ['value' => $value, 'matchType' => 'equals']]]);
        $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('paging.filtered', 1);
    }

    foreach (['contains', 'starts_with', 'ends_with'] as $matchType) {
        $intent = nativeBackendIntent(['unicode_name' => ['search' => ['value' => 'cafe', 'matchType' => $matchType]]]);
        $rows = $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()->json('data');

        if ($backend === 'sqlite') {
            $pattern = match ($matchType) {
                'contains' => '%cafe%', 'starts_with' => 'cafe%', 'ends_with' => '%cafe',
            };
            $native = DB::connection(NativeBackendFixture::$connection)->table('dapter_native_records')->where('tenant', 1)->whereRaw("unicode_name LIKE ? ESCAPE '!'", [$pattern])->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
            expect($native)->toBe($matchType === 'ends_with' ? [4, 5] : [2, 4, 5]);
        }
        expect(array_column($rows, 'id'))->toBe([4]);
    }

    if ($backend === 'mysql' || $backend === 'pgsql') {
        $columns = DB::connection(NativeBackendFixture::$connection)->getSchemaBuilder()->getColumns('dapter_native_records');
        $column = collect($columns)->firstWhere('name', 'unicode_name');
        expect($column['collation'])->toBe($backend === 'mysql' ? 'utf8mb4_bin' : 'C');
    }
})->with('native backend sources');

it('keeps source literal policy independent of observed native column collations', function (string $backend): void {
    $collations = match ($backend) {
        'mysql' => ['utf8mb4_general_ci', 'utf8mb4_0900_ai_ci'],
        'sqlite' => ['NOCASE'],
        default => ['C'],
    };

    foreach ($collations as $collation) {
        $source = prepareNativeBackend($backend, $collation);

        foreach (['equals', 'contains', 'starts_with', 'ends_with'] as $matchType) {
            $expected = match (true) {
                $backend === 'sqlite' && ($matchType === 'contains' || $matchType === 'starts_with') => [2, 4, 5],
                $backend === 'sqlite' => [4, 5],
                $backend === 'pgsql' => [4],
                $matchType === 'equals' && $collation === 'utf8mb4_0900_ai_ci' => [1, 2, 3, 4, 5],
                $matchType === 'contains' || $matchType === 'starts_with' => [1, 2, 3, 4, 5],
                default => [1, 3, 4, 5],
            };
            $intent = nativeBackendIntent(['unicode_name' => ['search' => ['value' => 'cafe', 'matchType' => $matchType]]]);
            $pattern = match ($matchType) {
                'equals' => 'cafe', 'contains' => '%cafe%', 'starts_with' => 'cafe%', 'ends_with' => '%cafe',
            };
            $native = DB::connection(NativeBackendFixture::$connection)->table('dapter_native_records')->where('tenant', 1);
            $native = $matchType === 'equals' ? $native->where('unicode_name', '=', 'cafe') : $native->whereRaw("unicode_name LIKE ? ESCAPE '!'", [$pattern]);
            expect($native->orderBy('id')->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all())->toBe($expected);
            $options = NativeBackendFixture::options();
            NativeBackendFixture::$settings = $options->strictLiteralSearch(false);
            $response = $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()->assertJsonPath('paging.filtered', 5);
            expect(array_column($response->json('data'), 'id'))->toBe([1, 2, 3, 4, 5]);
            NativeBackendFixture::$settings = $options->strictLiteralSearch();
            $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()->assertJsonPath('paging.filtered', 1)->assertJsonPath('data.0.id', 4);
        }
    }
})->with('native SQL sources');

it('accepts numeric JSON search zero without accepting a string or Boolean zero', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    $intent = nativeBackendIntent(['quantity' => ['search' => ['value' => 0, 'matchType' => 'equals']]]);
    $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1)->assertJsonPath('paging.filtered', 1)
        ->assertJsonPath('requested.fields.quantity.search.value', 0);

    NativeBackendFixture::$reads = 0;
    DB::connection(NativeBackendFixture::$connection)->flushQueryLog();

    foreach (['0', false, null] as $value) {
        $intent = nativeBackendIntent(['quantity' => ['search' => ['value' => $value, 'matchType' => 'equals']]]);
        $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertStatus(422);
    }

    expect(NativeBackendFixture::$reads)->toBe(0)
        ->and(DB::connection(NativeBackendFixture::$connection)->getQueryLog())->toBe([]);
})->with('native backend sources');

it('rejects wrong scalar input domains before calling the source', function (string $backend): void {
    $source = prepareNativeBackend($backend);

    foreach ([['quantity', '0'], ['quantity', false], ['code', 0], ['code', false], ['enabled', 0], ['enabled', '0']] as [$field, $value]) {
        $intent = nativeBackendIntent([$field => ['filters' => [$field => ['value' => $value]]]]);
        $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertStatus(422);
    }

    expect(NativeBackendFixture::$reads)->toBe(0)
        ->and(DB::connection(NativeBackendFixture::$connection)->getQueryLog())->toBe([]);
})->with('native backend sources');

it('orders ties consistently across adjacent pages without changing the supplied source', function (string $backend): void {
    $source = prepareNativeBackend($backend);

    if ($source === 'NativeSqlRecords') {
        NativeBackendFixture::$builder = NativeBackendRecord::on(NativeBackendFixture::$connection)->orderBy('id', 'desc');
    }

    $original = NativeBackendFixture::$builder?->toSql();
    $bindings = NativeBackendFixture::$builder?->getBindings();
    $order = ['kind' => 'fields', 'terms' => [['field' => 'rank', 'direction' => 'asc', 'sortType' => 'native']]];
    $first = $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => nativeBackendIntent(order: $order)])->assertOk()
        ->assertJsonPath('paging.total', 100)->assertJsonPath('paging.filtered', 100)
        ->assertJsonPath('requested.order', $order)->json('data');
    $second = $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => nativeBackendIntent(order: $order, page: 2)])->assertOk()
        ->assertJsonPath('paging.current', 2)->assertJsonPath('paging.has_previous', true)->json('data');

    expect(array_column($first, 'id'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($second, 'id'))->toBe([6, 7, 8, 9, 10])
        ->and(NativeBackendFixture::$builder?->toSql())->toBe($original)
        ->and(NativeBackendFixture::$builder?->getBindings())->toBe($bindings);
})->with('native backend sources');

it('validates the final operation before any source or SQL work', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    $intent = nativeBackendIntent([
        'label' => ['search' => ['value' => 'Tools', 'matchType' => 'contains']],
        'quantity' => ['filters' => ['quantity' => ['value' => 0]]],
    ], ['kind' => 'fields', 'terms' => [['field' => 'rank', 'direction' => 'sideways', 'sortType' => 'native']]]);

    $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertStatus(422)->assertJsonMissingPath('data');

    expect(NativeBackendFixture::$reads)->toBe(0)
        ->and(DB::connection(NativeBackendFixture::$connection)->getQueryLog())->toBe([]);
})->with('native backend sources');

it('keeps the v1 response identical when managed definitions are installed', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    NativeBackendFixture::$managed = false;
    $original = $this->postJson('/dapter/data/'.$source, ['version' => 1])->assertOk()->json();
    NativeBackendFixture::$managed = true;

    $this->postJson('/dapter/data/'.$source, ['version' => 1])->assertOk()->assertExactJson($original)
        ->assertJsonMissingPath('metadata');
})->with('native backend sources');

it('combines field predicates with the grouped source OR and tenant scope', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    $intent = nativeBackendIntent([
        'label' => ['search' => ['value' => 'Record', 'matchType' => 'starts_with']],
        'status' => ['filters' => ['status' => ['value' => 'inactive']]],
    ]);

    $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()
        ->assertJsonCount(5, 'data')->assertJsonPath('data.0.id', 5)
        ->assertJsonPath('paging.total', 100)->assertJsonPath('paging.filtered', 96)
        ->assertJsonMissingPath('data.0.secret');
})->with('native backend sources');

it('preserves tenant scope around server-owned OR searches when managed filters are cleared', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    NativeBackendFixture::$builder = NativeBackendRecord::on(NativeBackendFixture::$connection)
        ->whereLike('name', 'Tools%')->orWhereLike('name', 'Record 101');
    $filtered = nativeBackendIntent([
        'label' => ['search' => ['value' => 'kit', 'matchType' => 'contains']],
        'status' => ['filters' => ['status' => ['value' => 'active']]],
    ]);

    $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $filtered])->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1)
        ->assertJsonPath('paging.total', 2)->assertJsonPath('paging.filtered', 1);
    $cleared = $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => nativeBackendIntent()])->assertOk()
        ->assertJsonCount(2, 'data')->assertJsonPath('paging.total', 2)->assertJsonPath('paging.filtered', 2);

    expect(array_column($cleared->json('data'), 'id'))->toBe([1, 2]);
})->with('native SQL sources');

it('searches trusted hidden targets without exposing model attributes or SQL targets', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    NativeBackendFixture::$settings = StoreOptions::make()->publicAccess()->pageSize(5)
        ->searchableField('private_note', [MatchType::Equals], target: 'secret');
    $intent = nativeBackendIntent(['private_note' => ['search' => ['value' => 'Server-only note 1', 'matchType' => 'equals']]]);

    $response = $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent])->assertOk()
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1)
        ->assertJsonMissingPath('data.0.secret');

    expect($response->content())->not->toContain('"secret"', 'dapter_native_records');
})->with('native SQL sources');

it('rejects scope-added limits before executing SQL reads', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    NativeBackendRecord::addGlobalScope('window', static fn (Builder $query): Builder => $query->limit(1));

    $this->postJson('/dapter/data/'.$source, ['version' => 2])->assertStatus(500)->assertJsonMissingPath('data');

    expect(DB::connection(NativeBackendFixture::$connection)->getQueryLog())->toBe([]);
})->with('native SQL sources');

it('accepts textual UUID columns and rejects native UUID comparisons before listing reads', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    $connection = DB::connection(NativeBackendFixture::$connection);
    $connection->getSchemaBuilder()->table('dapter_native_records', static function (Blueprint $table): void {
        $table->uuid('uuid_value')->nullable();
    });
    $connection->table('dapter_native_records')->where('id', 1)->update(['uuid_value' => '123e4567-e89b-12d3-a456-426614174000']);
    NativeBackendFixture::$settings = StoreOptions::make()->publicAccess()->pageSize(5)->searchableField('uuid_value');
    $connection->flushQueryLog();
    $intent = nativeBackendIntent(['uuid_value' => ['search' => ['value' => '123e4567', 'matchType' => 'contains']]]);

    $response = $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => $intent]);

    if ($backend === 'pgsql') {
        $response->assertStatus(500)->assertJsonMissingPath('data');
        expect(array_filter($connection->getQueryLog(), static fn (array $query): bool => str_contains(strtolower($query['query']), 'count(')))->toBe([]);
    } else {
        $response->assertOk()->assertJsonPath('paging.filtered', 1)->assertJsonPath('data.0.id', 1);
    }
})->with('native SQL sources');

it('rejects native JSON ordering before listing reads while retaining textual storage support', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    $connection = DB::connection(NativeBackendFixture::$connection);
    $connection->getSchemaBuilder()->table('dapter_native_records', static function (Blueprint $table): void {
        $table->json('json_value')->nullable();
    });
    NativeBackendFixture::$settings = StoreOptions::make()->publicAccess()->pageSize(5)->orderableField('json_value');
    $connection->flushQueryLog();
    $order = ['kind' => 'fields', 'terms' => [['field' => 'json_value', 'direction' => 'asc', 'sortType' => 'native']]];

    $response = $this->postJson('/dapter/data/'.$source, ['version' => 2, 'state' => nativeBackendIntent(order: $order)]);

    if ($backend === 'sqlite') {
        $response->assertOk()->assertJsonPath('paging.filtered', 100);
    } else {
        $response->assertStatus(500)->assertJsonMissingPath('data');
        expect(array_filter($connection->getQueryLog(), static fn (array $query): bool => str_contains(strtolower($query['query']), 'count(')))->toBe([]);
    }
})->with('native SQL sources');

it('rejects absent and incompatible SQL field declarations before listing rows', function (string $backend): void {
    $source = prepareNativeBackend($backend);
    NativeBackendFixture::$settings = StoreOptions::make()->publicAccess()
        ->searchableField('label', target: 'missing_column');

    $this->postJson('/dapter/data/'.$source, ['version' => 2])->assertStatus(500)->assertJsonMissingPath('data');

    NativeBackendFixture::$settings = StoreOptions::make()->publicAccess()
        ->filterableField('code', 'code', [0, 1], valueType: ValueType::Integer);

    $this->postJson('/dapter/data/'.$source, ['version' => 2])->assertStatus(500)->assertJsonMissingPath('data');

    $queries = DB::connection(NativeBackendFixture::$connection)->getQueryLog();
    expect(array_filter($queries, static fn (array $query): bool => preg_match('/\b(?:from|FROM) ["`]?dapter_native_records["`]?\b/', $query['query']) === 1))->toBe([]);
})->with('native SQL sources');

it('restricts live identity lookups inside the complete cloned mandatory predicate before hydration', function (string $backend): void {
    prepareNativeBackend($backend);
    $builder = NativeBackendRecord::on(NativeBackendFixture::$connection)->where('id', 1)->orWhere('id', 2);
    $sql = $builder->toSql();
    $bindings = $builder->getBindings();
    $driver = new EloquentStoreDriver($builder);
    $hydrated = [];
    NativeBackendRecord::retrieved(static function (NativeBackendRecord $row) use (&$hydrated): void {
        $hydrated[] = $row->getKey();
    });
    DB::connection(NativeBackendFixture::$connection)->flushQueryLog();
    $rows = $driver->identifiedRows($driver->rowIdentity(null), [2, 101]);
    $queries = DB::connection(NativeBackendFixture::$connection)->getQueryLog();
    expect(array_map(static fn (mixed $row): mixed => $row->getKey(), $rows))->toBe([2])
        ->and($hydrated)->toBe([2])->and($queries)->toHaveCount(1)
        ->and(strtolower($queries[0]['query']))->toContain(' in ', 'limit 101')->not->toContain('count(')
        ->and($builder->toSql())->toBe($sql)->and($builder->getBindings())->toBe($bindings);
})->with('native SQL sources');

it('keeps SQL affinity from turning string public identifiers into integer identities', function (string $backend): void {
    prepareNativeBackend($backend);
    $driver = new EloquentStoreDriver(NativeBackendRecord::on(NativeBackendFixture::$connection));
    $rows = $driver->identifiedRows($driver->rowIdentity(null), ['1', 2, 101]);
    expect(array_map(static fn (mixed $row): mixed => $row->getKey(), $rows))->toBe([2]);
    DB::connection(NativeBackendFixture::$connection)->table('dapter_native_records')->where('id', 2)->update(['tenant' => 2]);
    expect($driver->identifiedRows($driver->rowIdentity(null), [2]))->toBe([]);
})->with('native SQL sources');

it('bounds live native hydration and rejects ambiguous output identities', function (string $backend): void {
    prepareNativeBackend($backend);
    $template = NativeBackendFixture::records()[2];
    $extra = [];
    foreach (range(102, 220) as $id) {
        $extra[] = [...$template, 'id' => $id, 'tenant' => 1];
    }
    DB::connection(NativeBackendFixture::$connection)->table('dapter_native_records')->insert($extra);
    $provider = new class implements RowIdentityProvider
    {
        public function identify(mixed $rawRow): string
        {
            return $rawRow->getAttribute('code');
        }

        public function selector(): SqlIdentitySelector
        {
            return new SqlIdentitySelector('dapter_native_records.code', 'code');
        }

        public function lookup(int|string $publicIdentifier): int|string
        {
            return $publicIdentifier;
        }
    };
    $hydrated = 0;
    NativeBackendRecord::retrieved(static function () use (&$hydrated): void {
        $hydrated++;
    });
    $driver = new EloquentStoreDriver(NativeBackendRecord::on(NativeBackendFixture::$connection));
    expect(fn () => $driver->identifiedRows($provider, ['00']))->toThrow(DapterException::class);
    expect($hydrated)->toBe(101);
    DB::connection(NativeBackendFixture::$connection)->table('dapter_native_records')->where('id', '>', 4)->delete();
    expect(fn () => $driver->identifiedRows($provider, ['00']))->toThrow(DapterException::class);
})->with('native SQL sources');

it('attaches committed live work to the deepest transaction record of its named native connection', function (string $backend): void {
    prepareNativeBackend($backend);
    $connection = DB::connection(NativeBackendFixture::$connection);
    config()->set('database.connections.native-live-other', ['driver' => 'sqlite', 'database' => ':memory:']);
    $other = DB::connection('native-live-other');
    $calls = [];
    $connection->beginTransaction();
    $other->beginTransaction();
    (new CommitBoundary)->after(NativeBackendFixture::$connection, static function () use (&$calls): void {
        $calls[] = 'committed';
    });
    $other->commit();
    expect($calls)->toBe([]);
    $connection->rollBack();
    expect($calls)->toBe([]);
    $connection->beginTransaction();
    $connection->beginTransaction();
    $other->beginTransaction();
    (new CommitBoundary)->after(NativeBackendFixture::$connection, static function () use (&$calls): void {
        $calls[] = 'nested';
    });
    $connection->commit();
    expect($calls)->toBe([]);
    $connection->commit();
    expect($calls)->toBe(['nested']);
    $other->rollBack();
})->with('native SQL sources');
