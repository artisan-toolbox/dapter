<?php

declare(strict_types=1);

use App\DataSources\PagingFixture;
use App\DataSources\PagingRecord;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResultIdentity;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/PagingSources.php';

/** @return array<string,array{string}> */
function pagingBackends(bool $sql = false): array
{
    $backends = $sql ? ['SQLite' => ['sqlite']] : ['Collection' => ['array'], 'SQLite' => ['sqlite']];

    return getenv('DAPTER_NATIVE_DATABASES') === '1' ? [...$backends, 'MySQL' => ['mysql'], 'PostgreSQL' => ['pgsql']] : $backends;
}

function pagingPrepare(string $backend, PagingMode $mode = PagingMode::Cursor): string
{
    PagingFixture::$builder = null;
    PagingFixture::$counts = 0;
    PagingFixture::$scope = 'paging-fixture-v1';
    PagingFixture::$connection = in_array($backend, ['array', 'sqlite'], true) ? 'testing' : 'dapter_phase4_'.$backend;
    PagingFixture::$options = StoreOptions::make()->publicAccess()->pageSize(2)->pageSizeOptions([2, 3])->pagingMode($mode)->orderableField('rank', valueType: ValueType::Integer)->orderableField('name', valueType: ValueType::String)->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)])));
    PagingFixture::$rows = [
        ['id' => 1, 'tenant' => 1, 'name' => 'A', 'rank' => 1],
        ['id' => 2, 'tenant' => 1, 'name' => 'B', 'rank' => 1],
        ['id' => 3, 'tenant' => 1, 'name' => 'C', 'rank' => 2],
        ['id' => 4, 'tenant' => 1, 'name' => 'D', 'rank' => 2],
        ['id' => 5, 'tenant' => 1, 'name' => 'E', 'rank' => 3],
        ['id' => 9, 'tenant' => 2, 'name' => 'Private', 'rank' => 0],
    ];

    if ($backend === 'array') {
        return 'PagingArray';
    }

    if ($backend !== 'sqlite') {
        $prefix = $backend === 'mysql' ? 'DAPTER_MYSQL_' : 'DAPTER_PGSQL_';
        $database = getenv($prefix.'DATABASE');

        throw_if(! is_string($database) || ! preg_match('/\Adapter_[A-Za-z0-9_]+\z/D', $database), RuntimeException::class, 'An isolated dapter_ native database is required.');
        config()->set('database.connections.'.PagingFixture::$connection, ['driver' => $backend, 'host' => getenv($prefix.'HOST') ?: '127.0.0.1', 'port' => getenv($prefix.'PORT') ?: ($backend === 'mysql' ? '3306' : '5432'), 'database' => $database, 'username' => getenv($prefix.'USERNAME') ?: ($backend === 'mysql' ? 'root' : 'allancarvalho'), 'password' => getenv($prefix.'PASSWORD') ?: '', 'charset' => $backend === 'mysql' ? 'utf8mb4' : 'utf8', 'collation' => $backend === 'mysql' ? 'utf8mb4_bin' : null, 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);
    }
    $schema = DB::connection(PagingFixture::$connection)->getSchemaBuilder();
    $schema->dropIfExists('dapter_phase4_records');
    $schema->create('dapter_phase4_records', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('tenant');
        $table->string('name');
        $table->integer('rank')->nullable();
    });
    DB::connection(PagingFixture::$connection)->table('dapter_phase4_records')->insert(PagingFixture::$rows);

    return 'PagingSql';
}

/** @return Builder<PagingRecord> */
function pagingBuilder(): Builder
{
    return (new PagingRecord)->setConnection(PagingFixture::$connection)->newQuery()->where('tenant', 1);
}

/**
 * @param  array<string,mixed>|null  $order
 * @return array<string,mixed>
 */
function pagingIntent(?string $cursor = null, ?array $order = null, ?int $current = null): array
{
    return ['version' => 2, 'state' => ['version' => 2, 'fields' => (object) [], 'global' => (object) [], 'searchMatch' => 'all', 'order' => $order ?? ['kind' => 'default'], 'paging' => $current === null ? ['mode' => 'cursor', 'cursor' => $cursor, 'per_page' => 2] : ['mode' => 'simple', 'current' => $current, 'per_page' => 2]]];
}
