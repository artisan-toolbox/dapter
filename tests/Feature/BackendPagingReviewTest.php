<?php

declare(strict_types=1);

use App\DataSources\PagingFixture;
use App\DataSources\PagingRecord;
use App\DataSources\PagingSql;
use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Drivers\DriverRegistry;
use ArtisanToolbox\Dapter\Drivers\SqliteStoreDriver;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResultIdentity;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Fixtures/PagingHarness.php';

afterEach(function (): void {
    PagingFixture::$rows = [];
    PagingFixture::$builder = null;
    PagingFixture::$options = null;
});

it('does not skip distinct numeric-looking collection string identities at a cursor boundary', function (string $direction): void {
    pagingPrepare('array');
    PagingFixture::$rows = [
        ['id' => '1', 'tenant' => 1, 'name' => 'A', 'rank' => 1],
        ['id' => '01', 'tenant' => 1, 'name' => 'B', 'rank' => 1],
        ['id' => '001', 'tenant' => 1, 'name' => 'C', 'rank' => 1],
    ];
    PagingFixture::$options = PagingFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::String)])));
    PagingFixture::$options = PagingFixture::$options->orderableField('id', valueType: ValueType::String);
    $order = ['kind' => 'fields', 'terms' => [['field' => 'id', 'direction' => $direction, 'sortType' => 'native']]];
    $first = $this->postJson('/dapter/data/PagingArray', pagingIntent(order: $order))->assertOk();
    expect(array_column($first->json('data'), 'id'))->toBe($direction === 'asc' ? ['001', '01'] : ['1', '01']);
    $second = $this->postJson('/dapter/data/PagingArray', pagingIntent($first->json('paging.next_cursor'), $order))->assertOk();
    $back = $this->postJson('/dapter/data/PagingArray', pagingIntent($second->json('paging.previous_cursor'), $order))->assertOk();
    expect(array_column($back->json('data'), 'id'))->toBe(array_column($first->json('data'), 'id'));
    $ids = [...array_column($first->json('data'), 'id'), ...array_column($second->json('data'), 'id')];
    sort($ids, SORT_STRING);
    expect($ids)->toBe(['001', '01', '1']);
})->with(['asc', 'desc']);

it('rejects colliding native cursor outputs instead of seeking with the wrong joined value', function (): void {
    pagingPrepare('sqlite');
    PagingFixture::$builder = (new PagingRecord)->setConnection('testing')->newQuery()->from('dapter_phase4_records as r')->where('r.tenant', 1)
        ->join('dapter_phase4_records as s', 's.id', '=', DB::raw('r.id + 1'))
        ->select(['r.id', 's.id', 'r.rank', 'r.name']);
    PagingFixture::$options = PagingFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('r.id', 'id', ValueType::Integer)])));
    $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation');
});

it('uses lexical default string identity order without relying on source positions', function (): void {
    pagingPrepare('array');
    PagingFixture::$rows = [
        ['id' => '1', 'tenant' => 1, 'name' => 'A', 'rank' => 1],
        ['id' => '01', 'tenant' => 1, 'name' => 'B', 'rank' => 1],
        ['id' => '001', 'tenant' => 1, 'name' => 'C', 'rank' => 1],
    ];
    PagingFixture::$options = PagingFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::String)])));
    $first = $this->postJson('/dapter/data/PagingArray', pagingIntent())->assertOk();
    expect(array_column($first->json('data'), 'id'))->toBe(['001', '01']);
    $second = $this->postJson('/dapter/data/PagingArray', pagingIntent($first->json('paging.next_cursor')))->assertOk();
    expect(array_column($second->json('data'), 'id'))->toBe(['1']);
});

it('keeps numeric-looking native string ordering unchanged for ordinary offset and simple reads', function (PagingMode $mode): void {
    pagingPrepare('array', $mode);
    PagingFixture::$rows = [
        ['id' => 1, 'tenant' => 1, 'name' => '2', 'rank' => 1],
        ['id' => 2, 'tenant' => 1, 'name' => '10', 'rank' => 1],
        ['id' => 3, 'tenant' => 1, 'name' => '1', 'rank' => 1],
    ];
    $intent = pagingIntent(order: ['kind' => 'fields', 'terms' => [['field' => 'name', 'direction' => 'asc', 'sortType' => 'native']]], current: 1);
    $intent['state']['paging']['mode'] = $mode->value;
    $first = $this->postJson('/dapter/data/PagingArray', $intent)->assertOk();
    expect(array_column($first->json('data'), 'id'))->toBe([3, 1]);
})->with([PagingMode::Offset, PagingMode::Simple]);

it('rejects wildcard cursor output collisions before selected handlers and listing SQL', function (array $columns): void {
    pagingPrepare('sqlite');
    PagingFixture::$builder = (new PagingRecord)->setConnection('testing')->newQuery()->from('dapter_phase4_records as r')->where('r.tenant', 1)
        ->join('dapter_phase4_records as s', 's.id', '=', DB::raw('r.id + 1'))->select($columns);
    PagingFixture::$options = PagingFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('r.id', 'id', ValueType::Integer)])))
        ->globalFilterable('visible', [1], static function (Builder $query, int $value): Builder {
            PagingFixture::$counts++;

            return $query;
        });
    $intent = pagingIntent();
    $intent['state']['global'] = ['filters' => ['visible' => ['value' => 1]]];
    DB::connection('testing')->enableQueryLog();
    $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation');
    expect(PagingFixture::$counts)->toBe(0);
    $listings = array_filter(DB::connection('testing')->getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "dapter_phase4_records"'));
    expect($listings)->toBe([]);
})->with([[['*']], [['r.*', 's.id']], [['r.id as id', 's.id as id', 'r.rank', 'r.name']]]);

it('rejects a joined cursor order whose output is absent from the selected relation', function (): void {
    pagingPrepare('sqlite');
    PagingFixture::$builder = (new PagingRecord)->setConnection('testing')->newQuery()->from('dapter_phase4_records as r')->where('r.tenant', 1)
        ->join('dapter_phase4_records as s', 's.id', '=', DB::raw('r.id + 1'))->select('r.*')->orderBy('s.rank');
    PagingFixture::$options = PagingFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('r.id', 'id', ValueType::Integer)])));
    $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation');
});

it('traverses explicit unique aliases and a base wildcard with an additional distinct joined alias', function (bool $wildcard): void {
    pagingPrepare('sqlite');
    $columns = $wildcard ? ['r.*', 's.id as next_id'] : ['r.id as row_id', 's.id as next_id', 'r.rank', 'r.name'];
    PagingFixture::$builder = (new PagingRecord)->setConnection('testing')->newQuery()->from('dapter_phase4_records as r')->where('r.tenant', 1)
        ->join('dapter_phase4_records as s', 's.id', '=', DB::raw('r.id + 1'))->select($columns);
    $output = $wildcard ? 'id' : 'row_id';
    PagingFixture::$options = PagingFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('r.id', $output, ValueType::Integer)])));
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertOk();
    $second = $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor')))->assertOk();
    expect([...array_column($first->json('data'), $output), ...array_column($second->json('data'), $output)])->toBe([1, 2, 3, 4]);
})->with([false, true]);

it('negotiates custom native drivers after applying declared query options', function (PagingMode $mode): void {
    pagingPrepare('sqlite', $mode);
    PagingFixture::$builder = pagingBuilder()->select(['id as row_id', 'tenant', 'rank', 'name']);
    PagingFixture::$options = PagingFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'row_id', ValueType::Integer)])))->driver('review_native');
    resolve(DriverRegistry::class)->register('review_native', static fn (PagingSql $source): StoreDriver => new SqliteStoreDriver($source->source()));
    $intent = pagingIntent(current: $mode === PagingMode::Cursor ? null : 1);
    $intent['state']['paging']['mode'] = $mode->value;
    $first = $this->postJson('/dapter/data/PagingSql', $intent)->assertOk();
    expect(array_column($first->json('data'), 'row_id'))->toBe([1, 2]);

    if ($mode === PagingMode::Cursor) {
        $intent['state']['paging']['cursor'] = $first->json('paging.next_cursor');
    } else {
        $intent['state']['paging']['current'] = 2;
    }
    $second = $this->postJson('/dapter/data/PagingSql', $intent)->assertOk();
    expect(array_column($second->json('data'), 'row_id'))->toBe([3, 4]);
})->with(['offset' => [PagingMode::Offset], 'simple' => [PagingMode::Simple], 'cursor' => [PagingMode::Cursor]]);

it('binds equivalent prepared date values across independent builder instances', function (): void {
    pagingPrepare('sqlite');
    $builder = static fn (): Builder => pagingBuilder()->whereRaw('? = ?', [new DateTimeImmutable('2026-01-01 00:00:00'), new DateTimeImmutable('2026-01-01 00:00:00')]);
    PagingFixture::$builder = $builder();
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertOk();
    PagingFixture::$builder = $builder();
    $next = $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor')))->assertOk();
    expect(array_column($next->json('data'), 'id'))->toBe([3, 4]);
});

it('rejects changed selected SQL ahead of expiry without eligibility counts or row queries', function (): void {
    pagingPrepare('sqlite');
    $minimum = 1;
    PagingFixture::$options = PagingFixture::$options->exactCounts()->globalFilterable('minimum', [1], static function (Builder $query, int $value) use (&$minimum): Builder {
        return $query->where('rank', '>=', $minimum);
    });
    $intent = pagingIntent();
    $intent['state']['global'] = ['filters' => ['minimum' => ['value' => 1]]];
    $first = $this->postJson('/dapter/data/PagingSql', $intent)->assertOk();
    $intent['state']['paging']['cursor'] = $first->json('paging.next_cursor');
    $this->travel(900)->seconds();
    $minimum = 2;
    $connection = DB::connection('testing');
    $connection->enableQueryLog();
    $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
    $listings = array_filter($connection->getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "dapter_phase4_records"'));
    expect($listings)->toBe([]);
});

it('reports same-context native expiry before eligibility counts or row queries', function (): void {
    pagingPrepare('sqlite');
    PagingFixture::$options = PagingFixture::$options->exactCounts();
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertOk();
    $this->travel(900)->seconds();
    $connection = DB::connection('testing');
    $connection->enableQueryLog();
    $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor')))->assertStatus(409)->assertJsonPath('error.kind', 'cursor_expired');
    $listings = array_filter($connection->getQueryLog(), static fn (array $query): bool => str_contains($query['query'], 'from "dapter_phase4_records"'));
    expect($listings)->toBe([]);
});

it('keeps nested exists predicates isolated while adding cursor bounds', function (): void {
    pagingPrepare('sqlite');
    PagingFixture::$builder = pagingBuilder()->whereExists(static function (Illuminate\Database\Query\Builder $query): void {
        $query->selectRaw('1')->from('dapter_phase4_records as scope')->whereColumn('scope.id', 'dapter_phase4_records.id')->where('scope.tenant', 1);
    });
    $sql = PagingFixture::$builder->toSql();
    $bindings = PagingFixture::$builder->getBindings();
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertOk();
    $next = $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor')))->assertOk();
    expect(array_column($next->json('data'), 'id'))->toBe([3, 4]);
    expect(PagingFixture::$builder->toSql())->toBe($sql)->and(PagingFixture::$builder->getBindings())->toBe($bindings);
});

it('rejects native post-query transformations before listing cursor rows', function (): void {
    pagingPrepare('sqlite');
    PagingFixture::$builder = pagingBuilder()->afterQuery(static fn (Collection $rows): Collection => $rows->reverse());
    $connection = DB::connection('testing');
    $connection->enableQueryLog();
    $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation');
    expect($connection->getQueryLog())->toBe([]);
});

it('rejects collection null or invalid full-order carriers beyond the first window', function (mixed $value): void {
    pagingPrepare('array');
    PagingFixture::$rows[4]['rank'] = $value;
    $order = ['kind' => 'fields', 'terms' => [['field' => 'rank', 'direction' => 'asc', 'sortType' => 'native']]];
    $response = $this->postJson('/dapter/data/PagingArray', pagingIntent(order: $order));
    expect($response->status())->toBe($value === null ? 422 : 500);
})->with([[null], ['3'], [true]]);

it('keeps native implicit cursor identity private in the public effective order', function (bool $selected): void {
    pagingPrepare('sqlite');
    PagingFixture::$options = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor)->pageSize(2)->pageSizeOptions([2, 3])->orderableField('rank', valueType: ValueType::Integer);
    $order = $selected ? ['kind' => 'fields', 'terms' => [['field' => 'rank', 'direction' => 'desc', 'sortType' => 'native']]] : ['kind' => 'default'];
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent(order: $order))->assertOk();
    $expected = $selected ? ['kind' => 'terms', 'terms' => [['field' => 'rank', 'direction' => 'desc', 'sortType' => 'native']]] : ['kind' => 'source'];
    expect($first->json('state.order'))->toBe($expected);
    $second = $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor'), $order))->assertOk();
    expect(array_column($second->json('data'), 'id'))->toBe($selected ? [4, 1] : [3, 4]);
})->with([false, true]);

it('keeps default collection cursor order opaque while traversing declared identity', function (): void {
    pagingPrepare('array');
    $this->postJson('/dapter/data/PagingArray', pagingIntent())->assertOk()->assertJsonPath('state.order.kind', 'source');
});
