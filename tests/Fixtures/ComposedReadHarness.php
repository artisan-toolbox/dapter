<?php

declare(strict_types=1);

use App\DataSources\ComposedFixture;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/ComposedReadSources.php';

/** @return array<string,array{string}> */
function composedBackends(bool $sql = false): array
{
    $backends = $sql ? ['SQLite' => ['sqlite']] : ['Collection' => ['array'], 'SQLite' => ['sqlite']];

    return getenv('DAPTER_NATIVE_DATABASES') === '1' ? [...$backends, 'MySQL' => ['mysql'], 'PostgreSQL' => ['pgsql']] : $backends;
}

function composedPrepare(string $backend): string
{
    ComposedFixture::$connection = $backend === 'sqlite' || $backend === 'array' ? 'testing' : 'dapter_composed_'.$backend;
    $connection = ComposedFixture::$connection;
    ComposedFixture::$rows = [
        ['id' => 1, 'tenant' => 1, 'name' => 'Alpha', 'quantity' => 0, 'status' => 'active', 'rank' => 2],
        ['id' => 2, 'tenant' => 1, 'name' => 'Beta', 'quantity' => 2, 'status' => 'active', 'rank' => 1],
        ['id' => 3, 'tenant' => 1, 'name' => 'Alpha', 'quantity' => 3, 'status' => 'paused', 'rank' => 1],
        ['id' => 4, 'tenant' => 1, 'name' => 'Delta', 'quantity' => 0, 'status' => 'active', 'rank' => 1],
        ['id' => 5, 'tenant' => 1, 'name' => 'Echo', 'quantity' => 2, 'status' => 'active', 'rank' => 1],
        ['id' => 6, 'tenant' => 1, 'name' => 'Foxtrot', 'quantity' => 2, 'status' => 'active', 'rank' => 1],
        ['id' => 9, 'tenant' => 2, 'name' => 'Alpha', 'quantity' => 0, 'status' => 'active', 'rank' => 0],
    ];

    if ($backend === 'array') {
        return 'ComposedArray';
    }

    if ($backend !== 'sqlite') {
        $prefix = $backend === 'mysql' ? 'DAPTER_MYSQL_' : 'DAPTER_PGSQL_';
        $database = getenv($prefix.'DATABASE');

        throw_if(! is_string($database) || ! preg_match('/\Adapter_[A-Za-z0-9_]+\z/D', $database), RuntimeException::class, 'An isolated dapter_ native database is required.');
        config()->set('database.connections.'.$connection, ['driver' => $backend, 'host' => getenv($prefix.'HOST') ?: '127.0.0.1', 'port' => getenv($prefix.'PORT') ?: ($backend === 'mysql' ? '3306' : '5432'), 'database' => $database, 'username' => getenv($prefix.'USERNAME') ?: ($backend === 'mysql' ? 'root' : 'allancarvalho'), 'password' => getenv($prefix.'PASSWORD') ?: '', 'charset' => $backend === 'mysql' ? 'utf8mb4' : 'utf8', 'collation' => $backend === 'mysql' ? 'utf8mb4_bin' : null, 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);
    }
    $schema = DB::connection($connection)->getSchemaBuilder();
    $schema->dropIfExists('dapter_composed_lines');
    $schema->dropIfExists('dapter_composed_records');
    $schema->create('dapter_composed_records', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('tenant');
        $table->string('name');
        $table->integer('quantity');
        $table->string('status');
        $table->integer('rank');
    });
    $schema->create('dapter_composed_lines', static function (Blueprint $table): void {
        $table->integer('id')->primary();
        $table->integer('record_id');
        $table->integer('amount');
    });
    DB::connection($connection)->table('dapter_composed_records')->insert(ComposedFixture::$rows);
    DB::connection($connection)->table('dapter_composed_lines')->insert([
        ['id' => 11, 'record_id' => 1, 'amount' => 3], ['id' => 12, 'record_id' => 1, 'amount' => 5], ['id' => 21, 'record_id' => 2, 'amount' => 7], ['id' => 31, 'record_id' => 3, 'amount' => 2], ['id' => 91, 'record_id' => 9, 'amount' => 9],
    ]);

    return 'ComposedSql';
}

/**
 * @param  array<string,mixed>  $fields
 * @param  array<string,mixed>  $global
 * @param  array<string,mixed>|null  $order
 * @return array<string,mixed>
 */
function composedIntent(array $fields = [], array $global = [], string $match = 'all', ?array $order = null, int $page = 1): array
{
    return ['version' => 2, 'state' => ['version' => 2, 'fields' => (object) $fields, 'global' => (object) $global, 'searchMatch' => $match, 'order' => $order ?? ['kind' => 'default'], 'paging' => ['mode' => 'offset', 'current' => $page, 'per_page' => 5]]];
}
