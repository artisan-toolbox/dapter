<?php

declare(strict_types=1);

use App\DataSources\PagingFixture;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResultIdentity;
use ArtisanToolbox\Dapter\Options\VectorOptions;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Fixtures/PagingHarness.php';

/**
 * @param  list<int|float>  $value
 * @return array<string,mixed>
 */
function vectorIntent(PagingMode $mode = PagingMode::Offset, ?string $cursor = null, int $current = 1, array $value = [0, 0, 0]): array
{
    return ['version' => 2, 'state' => ['version' => 2, 'fields' => (object) ['embedding' => ['search' => ['value' => $value, 'matchType' => 'vector']]], 'global' => (object) [], 'searchMatch' => 'all', 'order' => ['kind' => 'default'], 'paging' => $mode === PagingMode::Cursor ? ['mode' => 'cursor', 'cursor' => $cursor, 'per_page' => 2] : ['mode' => $mode->value, 'current' => $current, 'per_page' => 2]]];
}

function vectorOptions(PagingMode $mode, float $maxDistance, int $dimensions = 3): StoreOptions
{
    return StoreOptions::make()->publicAccess()->pageSize(2)->pageSizeOptions([2, 3])->pagingMode($mode)
        ->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)])))
        ->searchableField('embedding', [MatchType::Vector], options: new FieldOptions(vector: new VectorOptions($dimensions, $maxDistance)))->exactCounts();
}

function prepareVectorSql(PagingMode $mode = PagingMode::Offset, float $maxDistance = 2.0): void
{
    pagingPrepare('pgsql', $mode);
    $connection = DB::connection(PagingFixture::$connection);
    $connection->getSchemaBuilder()->table('dapter_phase4_records', static function (Blueprint $table): void {
        $table->vector('embedding', 3)->nullable();
        $table->boolean('allowed')->default(true);
    });
    $connection->table('dapter_phase4_records')->insert([
        ['id' => 6, 'tenant' => 1, 'name' => 'Null vector', 'rank' => 3, 'allowed' => true],
        ['id' => 7, 'tenant' => 1, 'name' => 'Revoked', 'rank' => 0, 'allowed' => false],
    ]);
    foreach ([1 => '[0,0,0]', 2 => '[1,0,0]', 3 => '[-1,0,0]', 4 => '[2,0,0]', 5 => '[3,0,0]', 7 => '[0,0,0]', 9 => '[0,0,0]'] as $id => $value) {
        $connection->table('dapter_phase4_records')->where('id', $id)->update(['embedding' => $value]);
    }
    PagingFixture::$builder = pagingBuilder()->where('allowed', true);
    PagingFixture::$options = vectorOptions($mode, $maxDistance);
}

if (getenv('DAPTER_PGSQL_DATABASE') !== false) {
    afterEach(function (): void {
        DB::connection(PagingFixture::$connection)->getSchemaBuilder()->dropIfExists('dapter_phase4_records');
        PagingFixture::$rows = [];
        PagingFixture::$builder = null;
        PagingFixture::$options = null;
    });

    it('filters exact native L2 with inclusive bounds without changing identity order for every paging mode', function (PagingMode $mode): void {
        prepareVectorSql($mode);
        $first = $this->postJson('/dapter/data/PagingSql', vectorIntent($mode))->assertOk()
            ->assertJsonPath('paging.total', 6)->assertJsonPath('paging.filtered', 4)
            ->assertJsonPath('capabilities.fields.embedding.search', ['matchTypes' => ['vector'], 'valueType' => 'vector', 'vector' => ['engine' => 'pgsql', 'metric' => 'l2', 'dimensions' => 3, 'maxDistance' => 2, 'input' => 'prepared_vector', 'precision' => 'float32', 'maxCoordinateMagnitude' => 1000000, 'threshold' => 'inclusive', 'ranking' => 'filter_only']]);
        expect(array_column($first->json('data'), 'id'))->toBe([1, 2]);
        $second = $this->postJson('/dapter/data/PagingSql', vectorIntent($mode, $mode === PagingMode::Cursor ? $first->json('paging.next_cursor') : null, 2))->assertOk();
        expect(array_column($second->json('data'), 'id'))->toBe([3, 4]);
        $this->postJson('/dapter/data/PagingSql', vectorIntent($mode, value: [3, 0, 0]))->assertOk()->assertJsonPath('paging.filtered', 3)->assertJsonPath('data.0.id', 2);
        DB::connection(PagingFixture::$connection)->table('dapter_phase4_records')->where('id', 2)->update(['allowed' => false]);
        $fresh = $this->postJson('/dapter/data/PagingSql', vectorIntent($mode))->assertOk()->assertJsonPath('paging.total', 5)->assertJsonPath('paging.filtered', 3);
        expect(array_column($fresh->json('data'), 'id'))->toBe([1, 3]);

        if ($mode === PagingMode::Cursor) {
            $previous = $this->postJson('/dapter/data/PagingSql', vectorIntent($mode, $second->json('paging.previous_cursor')))->assertOk();
            expect(array_column($previous->json('data'), 'id'))->toBe([1]);
            $this->postJson('/dapter/data/PagingSql', vectorIntent($mode, $first->json('paging.next_cursor'), value: [1, 0, 0]))->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
            PagingFixture::$options = vectorOptions($mode, 3.0);
            $this->postJson('/dapter/data/PagingSql', vectorIntent($mode, $first->json('paging.next_cursor')))->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
        }
    })->with([PagingMode::Offset, PagingMode::Simple, PagingMode::Cursor]);

    it('excludes rows at distance one when the advertised threshold is just below one', function (): void {
        $threshold = 0.9999999999999999;
        prepareVectorSql(maxDistance: $threshold);
        $this->postJson('/dapter/data/PagingSql', vectorIntent())->assertOk()
            ->assertJsonPath('capabilities.fields.embedding.search.vector.maxDistance', $threshold)
            ->assertJsonPath('paging.filtered', 1)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1);
    });

    it('keeps optional vector counts unknown and permits zero vectors at distance zero', function (PagingMode $mode): void {
        prepareVectorSql($mode, 0.0);
        PagingFixture::$options = PagingFixture::$options->exactCounts(false);
        $this->postJson('/dapter/data/PagingSql', vectorIntent($mode))->assertOk()->assertJsonPath('paging.total', null)->assertJsonPath('paging.filtered', null)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', 1);
    })->with([PagingMode::Simple, PagingMode::Cursor]);

    it('rejects a vector target with the wrong native dimension without disclosing database details', function (): void {
        prepareVectorSql();
        PagingFixture::$options = vectorOptions(PagingMode::Offset, 1.0, 2);
        $this->postJson('/dapter/data/PagingSql', vectorIntent(value: [0, 0]))->assertStatus(500)->assertJsonPath('error.code', 'invalid_source')->assertJsonMissingPath('data')->assertJsonMissingPath('capabilities');
    });

    it('uses the installed extension namespace without relying on the search path', function (): void {
        prepareVectorSql();
        $connection = DB::connection(PagingFixture::$connection);
        $connection->beginTransaction();

        try {
            $connection->statement('create schema dapter_vector_extension_test');
            $connection->statement('alter extension vector set schema dapter_vector_extension_test');
            $this->postJson('/dapter/data/PagingSql', vectorIntent())->assertOk()->assertJsonPath('paging.filtered', 4)->assertJsonPath('data.0.id', 1);
        } finally {
            $connection->rollBack();
        }
    });

    it('uses the effective read or write connection for vector admission counts and rows', function (bool $write): void {
        prepareVectorSql();
        $connection = DB::connection(PagingFixture::$connection);
        $configuration = $connection->getConfig();
        $dsn = 'pgsql:host='.$configuration['host'].';port='.$configuration['port'].';dbname='.$configuration['database'];
        $reader = new PDO($dsn, $configuration['username'], $configuration['password']);
        $writer = new PDO($dsn, $configuration['username'], $configuration['password']);
        $connection->setPdo($writer)->setReadPdo($reader)->forgetRecordModificationState();
        PagingFixture::$builder = pagingBuilder()->where('allowed', true);

        if ($write) {
            PagingFixture::$builder->useWritePdo();
        }
        $writer->beginTransaction();

        try {
            $writer->exec('update dapter_phase4_records set allowed = false where id = 1');
            $this->postJson('/dapter/data/PagingSql', vectorIntent())->assertOk()->assertJsonPath('paging.total', $write ? 5 : 6)->assertJsonPath('paging.filtered', $write ? 3 : 4)->assertJsonPath('data.0.id', $write ? 2 : 1);
        } finally {
            $writer->rollBack();
        }
    })->with([false, true]);

    it('validates vector dimensions against the table resolved by the active PDO', function (): void {
        prepareVectorSql();
        $connection = DB::connection(PagingFixture::$connection);
        $configuration = $connection->getConfig();
        $dsn = 'pgsql:host='.$configuration['host'].';port='.$configuration['port'].';dbname='.$configuration['database'];
        $reader = new PDO($dsn, $configuration['username'], $configuration['password']);
        $reader->beginTransaction();

        try {
            $reader->exec('create temporary table dapter_phase4_records (id integer primary key, tenant integer, embedding vector(2), allowed boolean)');
            $reader->exec("insert into dapter_phase4_records values (42, 1, '[0,0]', true)");
            $connection->setReadPdo($reader)->forgetRecordModificationState();
            PagingFixture::$options = vectorOptions(PagingMode::Offset, 1.0, 2);
            $intent = vectorIntent(value: [0, 0]);
            $this->postJson('/dapter/data/PagingSql', $intent)->assertOk()->assertJsonPath('paging.filtered', 1)->assertJsonPath('data.0.id', 42);
            PagingFixture::$builder = pagingBuilder()->where('allowed', true)->useWritePdo();
            $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(500)->assertJsonPath('error.code', 'invalid_source');
        } finally {
            $reader->rollBack();
        }
    });
}
