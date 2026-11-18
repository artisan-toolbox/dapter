<?php

declare(strict_types=1);

use App\DataSources\ComposedArray;
use App\DataSources\ComposedFixture;
use App\DataSources\ComposedRecord;
use ArtisanToolbox\Dapter\Drivers\CollectionStoreDriver;
use ArtisanToolbox\Dapter\Drivers\ReadSelection;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use ArtisanToolbox\Dapter\Options\FixedOrderTerm;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResultIdentity;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

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

it('removes replaceable native source order before invoking a preset once', function (bool $fallback): void {
    composedPrepare('sqlite');
    ComposedFixture::$builder = (new ComposedRecord)->setConnection(ComposedFixture::$connection)->newQuery()->where('tenant', 1);

    if ($fallback) {
        ComposedFixture::$builder->orderBy('quantity');
    }
    $orders = null;
    ComposedFixture::$options = ComposedFixture::$options->globalOrderable('reverse', 'Reverse', static function (Builder $rows) use (&$orders): Builder {
        ComposedFixture::$applied++;
        $orders = $rows->getQuery()->orders;

        return $rows->orderByDesc('id');
    });
    $response = $this->postJson('/dapter/data/ComposedSql', composedIntent(order: ['kind' => 'preset', 'id' => 'reverse']))->assertOk()->assertJsonPath('state.order.opaque', true);
    expect(array_column($response->json('data'), 'id'))->toBe([6, 5, 4, 3, 2])->and($orders)->toBeNull()->and(ComposedFixture::$applied)->toBe(1);
})->with([false, true]);

it('rejects a preset that changes post global filter membership', function (string $backend): void {
    $source = composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->globalFilterable('active', ['active'], static fn (Collection|Builder $rows, string $status): Collection|Builder => $rows->where('status', $status))->globalOrderable('narrow', 'Narrow', static function (Collection|Builder $rows): Collection|Builder {
        ComposedFixture::$applied++;

        return $rows instanceof Collection ? $rows->take(1) : $rows->where('quantity', 0)->reorder()->orderBy('id');
    });
    DB::connection(ComposedFixture::$connection)->enableQueryLog();
    $this->postJson('/dapter/data/'.$source, composedIntent(global: ['filters' => ['active' => ['value' => 'active']]], order: ['kind' => 'preset', 'id' => 'narrow']))->assertStatus(500);
    $listingQueries = array_filter(DB::connection(ComposedFixture::$connection)->getQueryLog(), static fn (array $query): bool => preg_match('/\bfrom\s+["`]?dapter_composed_records\b/i', $query['query']) === 1);
    expect(ComposedFixture::$applied)->toBe(1)->and($listingQueries)->toBe([]);
})->with(composedBackends());

it('requires native presets to retain the declared prefix and complete identity order', function (string $case): void {
    composedPrepare('sqlite');
    ComposedFixture::$options = ComposedFixture::$options->orderableField('rank', valueType: ValueType::Integer)->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)]), [new FixedOrderTerm('rank')]))->globalOrderable('custom', 'Custom', static function (Builder $rows) use ($case): Builder {
        ComposedFixture::$applied++;

        return match ($case) {
            'prefix' => $rows->reorder()->orderBy('id'),
            'identity' => $rows->orderBy('name'),
            'valid' => $rows->orderByDesc('id'),
            default => throw new InvalidArgumentException('Unknown order preset test case.'),
        };
    });
    $response = $this->postJson('/dapter/data/ComposedSql', composedIntent(order: ['kind' => 'preset', 'id' => 'custom']));

    if ($case === 'valid') {
        $response->assertOk();
        expect(array_column($response->json('data'), 'id'))->toBe([6, 5, 4, 3, 2]);
    } else {
        $response->assertStatus(500);
    }
    expect(ComposedFixture::$applied)->toBe(1);
})->with(['prefix', 'identity', 'valid']);

it('rejects collection presets that replace a row with a duplicate or restore a filtered row', function (bool $restore): void {
    composedPrepare('array');
    ComposedFixture::$options = ComposedFixture::$options->globalFilterable('active', ['active'], static fn (Collection $rows, string $status): Collection => $rows->where('status', $status))->globalOrderable('replace', 'Replace', static function (Collection $rows) use ($restore): Collection {
        $rows = $rows->values();
        $rows[0] = $restore ? ComposedFixture::$rows[2] : $rows[1];

        return $rows;
    });
    $this->postJson('/dapter/data/ComposedArray', composedIntent(global: ['filters' => ['active' => ['value' => 'active']]], order: ['kind' => 'preset', 'id' => 'replace']))->assertStatus(500);
})->with([false, true]);

it('preserves collection object values and duplicate membership in an opaque preset', function (): void {
    composedPrepare('array');
    $first = (object) ['id' => 1, 'label' => 'First'];
    $second = (object) ['id' => 2, 'label' => 'Second'];
    ComposedFixture::$options = ComposedFixture::$options->globalOrderable('reverse', 'Reverse', static fn (Collection $rows): Collection => $rows->reverse()->values());
    $options = (new OptionsResolver)->resolve(new ComposedArray);
    $driver = new CollectionStoreDriver(collect([$first, $first, $second]));
    $page = $driver->execute(new ValidatedReadPlan($options, new OffsetRequest(1, 5), new ReadSelection(preset: 'reverse')));
    expect($page->rows)->toBe([$second, $first, $first])->and($page->total)->toBe(3)->and($page->filtered)->toBe(3);
});

it('rejects equal clones and in-place changes from selected collection handlers', function (string $kind, bool $mutate): void {
    composedPrepare('array');
    $transform = static function (Collection $rows) use ($mutate): Collection {
        return $rows->map(static function (stdClass $row) use ($mutate): stdClass {
            if ($mutate) {
                $row->label = 'Replaced';

                return $row;
            }

            return clone $row;
        });
    };
    ComposedFixture::$options = ComposedFixture::$options
        ->globalFilterable('select', ['all'], static fn (Collection $rows, string $value): Collection => $transform($rows))
        ->globalOrderable('sort', 'Sort', $transform);
    $plan = new ValidatedReadPlan((new OptionsResolver)->resolve(new ComposedArray), new OffsetRequest(1, 5), $kind === 'preset' ? new ReadSelection(preset: 'sort') : new ReadSelection(globalFilters: ['select' => ['all']]));
    $driver = new CollectionStoreDriver(collect([(object) ['id' => 1, 'label' => 'Notebook']]));
    expect(fn () => $driver->execute($plan))->toThrow(DapterException::class);
})->with(['preset', 'filter'])->with([false, true]);
