<?php

declare(strict_types=1);

use App\DataSources\PagingFixture;
use App\DataSources\PagingRecord;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\CountQueries;
use ArtisanToolbox\Dapter\Options\FixedOrderTerm;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResultIdentity;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Fixtures/PagingHarness.php';

afterEach(function (): void {
    if (PagingFixture::$rows !== [] && PagingFixture::$connection !== 'testing') {
        DB::connection(PagingFixture::$connection)->getSchemaBuilder()->dropIfExists('dapter_phase4_records');
    }
    PagingFixture::$rows = [];
    PagingFixture::$builder = null;
    PagingFixture::$options = null;
});

it('traverses tied cursor rows forward and backward with unknown counts and no offsets', function (string $backend): void {
    $source = pagingPrepare($backend);
    $order = ['kind' => 'fields', 'terms' => [['field' => 'rank', 'direction' => 'asc', 'sortType' => 'native']]];
    $first = $this->postJson('/dapter/data/'.$source, pagingIntent(order: $order))->assertOk()->assertJsonPath('paging.total', null)->assertJsonPath('paging.has_previous', false)->assertJsonPath('paging.has_next', true);
    expect(array_column($first->json('data'), 'id'))->toBe([1, 2]);
    $next = $first->json('paging.next_cursor');
    $second = $this->postJson('/dapter/data/'.$source, pagingIntent($next, $order))->assertOk()->assertJsonPath('paging.cursor', $next);
    expect(array_column($second->json('data'), 'id'))->toBe([3, 4]);
    $back = $this->postJson('/dapter/data/'.$source, pagingIntent($second->json('paging.previous_cursor'), $order))->assertOk()->assertJsonPath('paging.has_previous', false);
    expect(array_column($back->json('data'), 'id'))->toBe([1, 2]);
    $last = $this->postJson('/dapter/data/'.$source, pagingIntent($second->json('paging.next_cursor'), $order))->assertOk()->assertJsonPath('paging.has_next', false);
    expect(array_column($last->json('data'), 'id'))->toBe([5]);
})->with(pagingBackends());

it('uses simple lookahead without clamping empty ordinals or enabling count factories', function (string $backend): void {
    $source = pagingPrepare($backend, PagingMode::Simple);
    $first = $this->postJson('/dapter/data/'.$source, pagingIntent(current: 1))->assertOk()->assertJsonPath('paging.has_next', true)->assertJsonPath('paging.total', null);
    expect(array_column($first->json('data'), 'id'))->toBe([1, 2]);
    $this->postJson('/dapter/data/'.$source, pagingIntent(current: 9))->assertOk()->assertJsonPath('paging.current', 9)->assertJsonPath('paging.has_previous', true)->assertJsonPath('paging.has_next', false)->assertJsonCount(0, 'data');
    PagingFixture::$options = PagingFixture::$options->exactCounts();
    $this->postJson('/dapter/data/'.$source, pagingIntent(current: 9))->assertOk()->assertJsonPath('paging.current', 9)->assertJsonPath('paging.total', 5)->assertJsonPath('paging.filtered', 5);
})->with(pagingBackends());

it('uses fixed prefixes mixed directions and composite identities for cursor traversal', function (string $backend): void {
    $source = pagingPrepare($backend);
    PagingFixture::$options = PagingFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('tenant', 'tenant', ValueType::Integer), new IdentityColumn('id', 'id', ValueType::Integer)]), [new FixedOrderTerm('rank', SortDirection::Desc)]));
    $order = ['kind' => 'fields', 'terms' => [['field' => 'name', 'direction' => 'asc', 'sortType' => 'native']]];
    $ids = [];
    $cursor = null;
    do {
        $page = $this->postJson('/dapter/data/'.$source, pagingIntent($cursor, $order))->assertOk();
        $ids = [...$ids, ...array_column($page->json('data'), 'id')];
        $cursor = $page->json('paging.next_cursor');
    } while ($cursor !== null);
    expect($ids)->toBe([5, 3, 4, 1, 2]);
})->with(pagingBackends());

it('keeps native custom count factories dormant until exact counts are enabled', function (string $backend): void {
    pagingPrepare($backend, PagingMode::Simple);
    PagingFixture::$options = PagingFixture::$options->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)]), counts: static function (Builder $base, Builder $selected): CountQueries {
        PagingFixture::$counts++;

        return new CountQueries($base->reorder()->selectRaw('count(*) as aggregate'), $selected->reorder()->selectRaw('count(*) as aggregate'));
    }));
    DB::connection(PagingFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/PagingSql', pagingIntent(current: 1))->assertOk()->assertJsonPath('paging.total', null);
    expect(PagingFixture::$counts)->toBe(0);
    $exact = array_filter(DB::connection(PagingFixture::$connection)->getQueryLog(), static fn (array $query): bool => preg_match('/\Aselect count\(\*\) as ["`]?aggregate["`]?\s/i', $query['query']) === 1);
    expect($exact)->toBe([]);
    PagingFixture::$options = PagingFixture::$options->exactCounts();
    $this->postJson('/dapter/data/PagingSql', pagingIntent(current: 1))->assertOk()->assertJsonPath('paging.total', 5);
    expect(PagingFixture::$counts)->toBe(1);
})->with(pagingBackends(true));

it('rejects null order values beyond the page and admits an explicitly nonnull scope', function (string $backend): void {
    pagingPrepare($backend);
    DB::connection(PagingFixture::$connection)->table('dapter_phase4_records')->where('id', 5)->update(['rank' => null]);
    $order = ['kind' => 'fields', 'terms' => [['field' => 'rank', 'direction' => 'desc', 'sortType' => 'native']]];
    $this->postJson('/dapter/data/PagingSql', pagingIntent(order: $order))->assertStatus(422)->assertJsonPath('errors._dapter_kind.0', 'unsupported_operation');
    PagingFixture::$builder = pagingBuilder()->whereNotNull('rank');
    $this->postJson('/dapter/data/PagingSql', pagingIntent(order: $order))->assertOk()->assertJsonCount(2, 'data');
})->with(pagingBackends(true));

it('uses raw aliased values despite hidden attributes casts and authored fallback order', function (string $backend): void {
    pagingPrepare($backend);
    PagingFixture::$builder = pagingBuilder()->select(['id as row_key', 'tenant', 'rank as sort_rank', 'name'])->orderByDesc('sort_rank');
    PagingFixture::$builder->getModel()->setHidden(['row_key', 'sort_rank'])->mergeCasts(['row_key' => 'string', 'sort_rank' => 'boolean']);
    PagingFixture::$options = StoreOptions::make()->publicAccess()->pageSize(2)->pageSizeOptions([2, 3])->pagingMode(PagingMode::Cursor)->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'row_key', ValueType::Integer)])));
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertOk();
    expect(array_column($first->json('data'), 'name'))->toBe(['E', 'C']);
    $second = $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor')))->assertOk();
    expect(array_column($second->json('data'), 'name'))->toBe(['D', 'A']);
})->with(pagingBackends(true));

it('retains cursor progress across inserts and deletion of the boundary and returns tokenless empty windows', function (string $backend): void {
    $source = pagingPrepare($backend);
    $first = $this->postJson('/dapter/data/'.$source, pagingIntent())->assertOk();
    $new = [['id' => 0, 'tenant' => 1, 'name' => 'Before', 'rank' => 0], ['id' => 6, 'tenant' => 1, 'name' => 'After', 'rank' => 4]];

    if ($backend === 'array') {
        PagingFixture::$rows = array_values(array_filter([...PagingFixture::$rows, ...$new], static fn (array $row): bool => $row['id'] !== 2));
    } else {
        DB::connection(PagingFixture::$connection)->table('dapter_phase4_records')->insert($new);
        DB::connection(PagingFixture::$connection)->table('dapter_phase4_records')->where('id', 2)->delete();
    }
    $next = $this->postJson('/dapter/data/'.$source, pagingIntent($first->json('paging.next_cursor')))->assertOk();
    expect(array_column($next->json('data'), 'id'))->toBe([3, 4]);

    if ($backend === 'array') {
        PagingFixture::$rows = array_values(array_filter(PagingFixture::$rows, static fn (array $row): bool => $row['id'] <= 4));
    } else {
        DB::connection(PagingFixture::$connection)->table('dapter_phase4_records')->where('id', '>', 4)->delete();
    }
    $token = $next->json('paging.next_cursor');
    $this->postJson('/dapter/data/'.$source, pagingIntent($token))->assertOk()->assertJsonPath('paging.cursor', $token)->assertJsonPath('paging.previous_cursor', null)->assertJsonPath('paging.next_cursor', null)->assertJsonPath('paging.has_previous', false)->assertJsonPath('paging.has_next', false)->assertJsonCount(0, 'data');
    $restart = $this->postJson('/dapter/data/'.$source, pagingIntent())->assertOk();
    expect(array_column($restart->json('data'), 'id'))->toBe([0, 1]);
})->with(pagingBackends());

it('uses declared collection identity instead of insertion positions with no selected order', function (): void {
    pagingPrepare('array');
    PagingFixture::$rows = array_reverse(PagingFixture::$rows);
    $first = $this->postJson('/dapter/data/PagingArray', pagingIntent())->assertOk();
    expect(array_column($first->json('data'), 'id'))->toBe([1, 2]);
    $second = $this->postJson('/dapter/data/PagingArray', pagingIntent($first->json('paging.next_cursor')))->assertOk();
    expect(array_column($second->json('data'), 'id'))->toBe([3, 4]);
});

it('admits grouped key boundaries and rejects aggregate order before operation handlers', function (string $backend): void {
    pagingPrepare($backend);
    PagingFixture::$builder = pagingBuilder()->select('rank')->selectRaw('count(*) as total')->groupBy('rank')->havingRaw('count(*) >= ?', [1]);
    PagingFixture::$options = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor)->pageSize(2)->pageSizeOptions([2, 3])->orderableField('total', valueType: ValueType::Integer)->query(new QueryOptions(new ResultIdentity([new IdentityColumn('rank', 'rank', ValueType::Integer)])))->globalFilterable('show', [1], static function (Builder $query, int $value): Builder {
        PagingFixture::$counts++;

        return $query;
    });
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertOk();
    expect(array_column($first->json('data'), 'rank'))->toBe([1, 2]);
    $second = $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor')))->assertOk();
    expect(array_column($second->json('data'), 'rank'))->toBe([3]);
    $intent = pagingIntent(order: ['kind' => 'fields', 'terms' => [['field' => 'total', 'direction' => 'asc', 'sortType' => 'native']]]);
    $intent['state']['global'] = ['filters' => ['show' => ['value' => 1]]];
    $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(422)->assertJsonPath('errors._dapter_kind.0', 'unsupported_operation');
    expect(PagingFixture::$counts)->toBe(0);
})->with(pagingBackends(true));

it('traverses joined unique aliases while keeping protected JOIN WHERE and HAVING bindings', function (string $backend): void {
    pagingPrepare($backend);
    PagingFixture::$builder = (new PagingRecord)->setConnection(PagingFixture::$connection)->newQuery()->from('dapter_phase4_records as r')->where('r.tenant', 1)->join('dapter_phase4_records as s', static function (JoinClause $join): void {
        $join->on('s.id', '=', 'r.id')->where('s.tenant', 1);
    })->select(['r.id as row_id', 'r.rank', 'r.name'])->groupBy('r.id', 'r.rank', 'r.name')->havingRaw('count(*) >= ?', [1])->orderByDesc('r.rank');
    PagingFixture::$options = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor)->pageSize(2)->pageSizeOptions([2, 3])->query(new QueryOptions(new ResultIdentity([new IdentityColumn('r.id', 'row_id', ValueType::Integer)])));
    $sql = PagingFixture::$builder->toSql();
    $bindings = PagingFixture::$builder->getBindings();
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertOk();
    expect(array_column($first->json('data'), 'row_id'))->toBe([5, 3]);
    $second = $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor')))->assertOk();
    expect(array_column($second->json('data'), 'row_id'))->toBe([4, 1]);
    expect(PagingFixture::$builder->toSql())->toBe($sql)->and(PagingFixture::$builder->getBindings())->toBe($bindings);
})->with(pagingBackends(true));

it('binds source fallback order and protected scopes before executing a later cursor window', function (string $backend): void {
    pagingPrepare($backend);
    PagingFixture::$builder = pagingBuilder()->orderByDesc('rank');
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertOk();
    PagingFixture::$builder = pagingBuilder()->orderBy('rank');
    $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor')))->assertStatus(422)->assertJsonPath('errors._dapter_kind.0', 'validation');
    PagingFixture::$builder = pagingBuilder()->where('rank', '>', 1)->orderByDesc('rank');
    $this->postJson('/dapter/data/PagingSql', pagingIntent($first->json('paging.next_cursor')))->assertStatus(422);
})->with(pagingBackends(true));

it('computes native exact counts once without criteria and twice with selected criteria', function (string $backend): void {
    pagingPrepare($backend, PagingMode::Simple);
    PagingFixture::$options = PagingFixture::$options->exactCounts()->filterableField('rank', 'visible', [1, 2, 3], valueType: ValueType::Integer);
    $connection = DB::connection(PagingFixture::$connection);
    $connection->enableQueryLog();
    $this->postJson('/dapter/data/PagingSql', pagingIntent(current: 1))->assertOk()->assertJsonPath('paging.total', 5);
    $counts = static fn (): array => array_values(array_filter($connection->getQueryLog(), static fn (array $query): bool => preg_match('/\Aselect count\(\*\) as ["`]?aggregate["`]?\s/i', $query['query']) === 1));
    expect($counts())->toHaveCount(1);
    $connection->flushQueryLog();
    $intent = pagingIntent(current: 1);
    $intent['state']['fields'] = ['rank' => ['filters' => ['visible' => ['value' => 2]]]];
    $this->postJson('/dapter/data/PagingSql', $intent)->assertOk()->assertJsonPath('paging.total', 5)->assertJsonPath('paging.filtered', 2);
    expect($counts())->toHaveCount(2);
})->with(pagingBackends(true));

it('rejects a nonunique implicit model key instead of losing tied cursor rows', function (string $backend): void {
    pagingPrepare($backend);
    PagingFixture::$options = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor)->pageSize(2)->pageSizeOptions([2, 3]);
    PagingFixture::$builder = pagingBuilder();
    PagingFixture::$builder->getModel()->setKeyName('rank');
    $this->postJson('/dapter/data/PagingSql', pagingIntent())->assertStatus(422)->assertJsonPath('errors._dapter_kind.0', 'unsupported_operation');
})->with(pagingBackends(true));

it('uses the current ordered relation when live edits move records across a boundary', function (string $backend): void {
    $source = pagingPrepare($backend);
    $order = ['kind' => 'fields', 'terms' => [['field' => 'rank', 'direction' => 'asc', 'sortType' => 'native']]];
    $first = $this->postJson('/dapter/data/'.$source, pagingIntent(order: $order))->assertOk();

    if ($backend === 'array') {
        foreach (PagingFixture::$rows as &$row) {
            $row['rank'] = $row['id'] === 1 ? 3 : ($row['id'] === 5 ? 0 : $row['rank']);
        }
        unset($row);
    } else {
        DB::connection(PagingFixture::$connection)->table('dapter_phase4_records')->where('id', 1)->update(['rank' => 3]);
        DB::connection(PagingFixture::$connection)->table('dapter_phase4_records')->where('id', 5)->update(['rank' => 0]);
    }
    $second = $this->postJson('/dapter/data/'.$source, pagingIntent($first->json('paging.next_cursor'), $order))->assertOk();
    expect(array_column($second->json('data'), 'id'))->toBe([3, 4]);
    $last = $this->postJson('/dapter/data/'.$source, pagingIntent($second->json('paging.next_cursor'), $order))->assertOk();
    expect(array_column($last->json('data'), 'id'))->toBe([1]);
})->with(pagingBackends());

it('preserves native decimal boundary strings without converting them to floating point', function (string $backend): void {
    pagingPrepare($backend);
    DB::connection(PagingFixture::$connection)->getSchemaBuilder()->table('dapter_phase4_records', static function (Blueprint $table): void {
        $table->decimal('score', 30, 18)->nullable();
    });
    foreach ([1, 2, 3, 4, 5] as $id) {
        $value = $backend === 'sqlite' ? (string) $id : '1.00000000000000000'.$id;
        DB::connection(PagingFixture::$connection)->table('dapter_phase4_records')->where('id', $id)->update(['score' => $value]);
    }
    PagingFixture::$options = PagingFixture::$options->orderableField('score', valueType: ValueType::Number);
    $order = ['kind' => 'fields', 'terms' => [['field' => 'score', 'direction' => 'asc', 'sortType' => 'native']]];
    $first = $this->postJson('/dapter/data/PagingSql', pagingIntent(order: $order))->assertOk();
    $token = $first->json('paging.next_cursor');

    if ($backend !== 'sqlite') {
        $plain = resolve(StringEncrypter::class)->decryptString($token);
        expect($plain)->toContain('1.000000000000000002');
    }
    $second = $this->postJson('/dapter/data/PagingSql', pagingIntent($token, $order))->assertOk();
    expect(array_column($second->json('data'), 'id'))->toBe([3, 4]);
})->with(pagingBackends(true));
