<?php

declare(strict_types=1);

use App\DataSources\ComposedArray;
use App\DataSources\ComposedFixture;
use App\DataSources\ComposedManyFilter;
use App\DataSources\ComposedRecord;
use App\DataSources\ComposedStatus;
use App\DataSources\ComposedStatusFilter;
use ArtisanToolbox\Dapter\Contracts\EloquentSource;
use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Drivers\DriverCapabilities;
use ArtisanToolbox\Dapter\Drivers\DriverRegistry;
use ArtisanToolbox\Dapter\Drivers\RawPage;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\EnumBinding;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\FixedOrderTerm;
use ArtisanToolbox\Dapter\Options\HandlerOptions;
use ArtisanToolbox\Dapter\Options\HandlerRequirements;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResultIdentity;
use ArtisanToolbox\Dapter\Options\SearchTarget;
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
    if (ComposedFixture::$reads > 0 || ComposedFixture::$rows !== []) {
        $schema = DB::connection(ComposedFixture::$connection)->getSchemaBuilder();
        $schema->dropIfExists('dapter_composed_lines');
        $schema->dropIfExists('dapter_composed_records');
    }
    ComposedFixture::$builder = null;
    ComposedFixture::$options = null;
    ComposedFixture::$rows = [];
});

it('composes field searches and one global target group under All or Any while retaining filters and scope', function (string $backend): void {
    $source = composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->searchableField('name', [MatchType::Contains, MatchType::Equals])->globalSearchable(['quantity' => new SearchTarget('quantity', ValueType::Integer, MatchType::Equals)])->filterableField('status', 'visible', ['active'])->searchMatch(SearchMatch::All, [SearchMatch::All, SearchMatch::Any]);
    $fields = ['name' => ['search' => ['value' => 'Alpha', 'matchType' => 'equals']], 'status' => ['filters' => ['visible' => ['value' => 'active']]]];
    $all = $this->postJson('/dapter/data/'.$source, composedIntent($fields, ['search' => ['value' => '0']]))->assertOk()->assertJsonPath('paging.total', 6)->assertJsonPath('paging.filtered', 1);
    expect(array_column($all->json('data'), 'id'))->toBe([1]);
    $any = $this->postJson('/dapter/data/'.$source, composedIntent($fields, ['search' => ['value' => '0']], 'any'))->assertOk()->assertJsonPath('paging.filtered', 2)->assertJsonPath('requested.searchMatch', 'any')->assertJsonPath('requested.global.search.value', '0');
    expect(array_column($any->json('data'), 'id'))->toBe([1, 4]);
})->with(composedBackends());

it('retains incompatible numeric global input as an active false restriction and keeps zero active', function (string $backend): void {
    $source = composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->globalSearchable(['quantity' => new SearchTarget('quantity', ValueType::Integer, MatchType::Equals)]);
    foreach (['bad', ' 0', '0 ', '01', '1.0'] as $value) {
        $this->postJson('/dapter/data/'.$source, composedIntent(global: ['search' => ['value' => $value]]))->assertOk()->assertJsonPath('paging.filtered', 0)->assertJsonPath('requested.global.search.value', $value);
    }
    $this->postJson('/dapter/data/'.$source, composedIntent(global: ['search' => ['value' => '0']]))->assertOk()->assertJsonPath('paging.filtered', 2);
    $this->postJson('/dapter/data/'.$source, composedIntent(global: ['search' => ['value' => '']]))->assertOk()->assertJsonPath('paging.filtered', 6)->assertJsonPath('requested.global', []);
})->with(composedBackends());

it('validates every finite member before service construction and invokes a multiple enum handler once', function (string $backend): void {
    $source = composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->globalFilterable('status', ComposedStatus::class, ComposedManyFilter::class, multiple: true, options: new HandlerOptions(new EnumBinding(ComposedStatus::class, elements: true)));
    $this->postJson('/dapter/data/'.$source, composedIntent(global: ['filters' => ['status' => ['value' => ['active', 'unknown']]]]))->assertStatus(422);
    expect(ComposedFixture::$reads)->toBe(0)->and(ComposedFixture::$constructed)->toBe(0)->and(ComposedFixture::$applied)->toBe(0);
    $this->postJson('/dapter/data/'.$source, composedIntent(global: ['filters' => ['status' => ['value' => ['active', 'paused']]]]))->assertOk()->assertJsonPath('paging.total', 6)->assertJsonPath('paging.filtered', 6);
    expect(ComposedFixture::$constructed)->toBe(1)->and(ComposedFixture::$applied)->toBe(1);
})->with(composedBackends());

it('applies enum transforms once and keeps base totals independent of transformed filtered rows', function (string $backend): void {
    $source = composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->globalFilterable('status', ComposedStatus::class, ComposedStatusFilter::class);
    $this->postJson('/dapter/data/'.$source, composedIntent(global: ['filters' => ['status' => ['value' => 'paused']]]))->assertOk()->assertJsonPath('paging.total', 6)->assertJsonPath('paging.filtered', 1)->assertJsonPath('data.0.id', 3)->assertJsonPath('requested.global.filters.status.value', 'paused');
    expect(ComposedFixture::$constructed)->toBe(1)->and(ComposedFixture::$applied)->toBe(1);
})->with(composedBackends());

it('orders by requested priority with a declared fixed prefix and unique identity ties', function (string $backend): void {
    $source = composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->orderableField('rank', valueType: ValueType::Integer)->orderableField('quantity', valueType: ValueType::Integer)->orderableField('name')->multiOrder(true, 2)->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)]), [new FixedOrderTerm('rank')]));
    $order = ['kind' => 'fields', 'terms' => [['field' => 'quantity', 'direction' => 'desc', 'sortType' => 'native'], ['field' => 'name', 'direction' => 'asc', 'sortType' => 'native']]];
    $response = $this->postJson('/dapter/data/'.$source, composedIntent(order: $order))->assertOk()->assertJsonPath('state.order.terms.0.role', 'prefix')->assertJsonPath('state.order.terms.3.role', 'tie')->assertJsonPath('capabilities.global.stability.identity.0.id', 'id');
    expect(array_column($response->json('data'), 'id'))->toBe([3, 2, 5, 6, 4]);
    $this->postJson('/dapter/data/'.$source, composedIntent(order: $order, page: 2))->assertOk()->assertJsonPath('data.0.id', 1);
})->with(composedBackends());

it('keeps opaque order presets complete and never adds a collection post sort', function (string $backend): void {
    $source = composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->globalOrderable('reverse', 'Reverse', static function (Collection|Builder $source): Collection|Builder {
        ComposedFixture::$applied++;

        return $source instanceof Collection ? $source->sortByDesc('id')->values() : $source->reorder()->orderByDesc('id');
    });
    $response = $this->postJson('/dapter/data/'.$source, composedIntent(order: ['kind' => 'preset', 'id' => 'reverse']))->assertOk()->assertJsonPath('state.order', ['kind' => 'preset', 'id' => 'reverse', 'identity' => 'preset:reverse', 'opaque' => true])->assertJsonPath('capabilities.global.order.presets.0', ['id' => 'reverse', 'label' => 'Reverse']);
    expect(array_column($response->json('data'), 'id'))->toBe([6, 5, 4, 3, 2])->and(ComposedFixture::$applied)->toBe(1);
})->with(composedBackends());

it('negotiates actual source and engine requirements before constructing handlers', function (string $backend): void {
    $source = composedPrepare($backend);
    $requires = new HandlerRequirements(sourceType: EloquentSource::class, databases: ['pgsql']);
    ComposedFixture::$options = ComposedFixture::$options->globalFilterable('status', ComposedStatus::class, ComposedStatusFilter::class, options: new HandlerOptions(requires: $requires));
    $response = $this->postJson('/dapter/data/'.$source, composedIntent(global: ['filters' => ['status' => ['value' => 'active']]]));

    if ($backend === 'pgsql') {
        $response->assertOk();
        expect(ComposedFixture::$constructed)->toBe(1);
    } else {
        $response->assertStatus(500);
        expect(ComposedFixture::$constructed)->toBe(0)->and(ComposedFixture::$applied)->toBe(0);
    }
})->with(composedBackends());

it('preserves heterogeneous finite wire members without enum binding or scalar coercion', function (string $backend): void {
    $source = composedPrepare($backend);
    $received = 'not called';
    ComposedFixture::$options = ComposedFixture::$options->globalFilterable('flags', [null, false, 0, '0', ''], static function (Collection|Builder $source, mixed $value) use (&$received): Collection|Builder {
        $received = $value;

        return $source;
    });
    foreach ([null, false, 0, '0', ''] as $value) {
        $this->postJson('/dapter/data/'.$source, composedIntent(global: ['filters' => ['flags' => ['value' => $value]]]))->assertOk();
        expect($received)->toBe($value);
    }
})->with(composedBackends());

it('keeps automatic multiple enum field filters as membership operations', function (string $backend): void {
    $source = composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->filterableField('status', 'allowed', ComposedStatus::class, multiple: true);
    $this->postJson('/dapter/data/'.$source, composedIntent(['status' => ['filters' => ['allowed' => ['value' => ['paused']]]]]))->assertOk()->assertJsonPath('paging.total', 6)->assertJsonPath('paging.filtered', 1)->assertJsonPath('data.0.id', 3);
    expect(ComposedFixture::$constructed)->toBe(0)->and(ComposedFixture::$applied)->toBe(0);
})->with(composedBackends());

it('rejects duplicate and over limit field order requests before obtaining a source', function (): void {
    composedPrepare('array');
    ComposedFixture::$options = ComposedFixture::$options->orderableField('name')->orderableField('rank')->orderableField('quantity')->multiOrder(true, 2);
    $term = static fn (string $field): array => ['field' => $field, 'direction' => 'asc', 'sortType' => 'native'];
    foreach ([[$term('name'), $term('name')], [$term('name'), $term('rank'), $term('quantity')]] as $terms) {
        $this->postJson('/dapter/data/ComposedArray', composedIntent(order: ['kind' => 'fields', 'terms' => $terms]))->assertStatus(422);
    }
    expect(ComposedFixture::$reads)->toBe(0);
});

it('uses an explicit literal output key without interpreting it as a nested row path', function (): void {
    composedPrepare('array');
    ComposedFixture::$rows = [['id' => 1, 'tenant' => 1, 'display.name' => 'Alpha'], ['id' => 2, 'tenant' => 1, 'display.name' => 'Beta']];
    ComposedFixture::$options = ComposedFixture::$options->searchableField('label', [MatchType::Equals], target: 'name', options: new FieldOptions('display.name'));
    $this->postJson('/dapter/data/ComposedArray', composedIntent(['label' => ['search' => ['value' => 'Alpha', 'matchType' => 'equals']]]))->assertOk()->assertJsonPath('paging.filtered', 1)->assertJsonPath('data.0.id', 1);
});

it('keeps strict legacy read capabilities when composed declarations are installed', function (): void {
    composedPrepare('array');
    ComposedFixture::$options = ComposedFixture::$options->multiOrder(true, 3)->searchMatch(SearchMatch::Any, [SearchMatch::All, SearchMatch::Any])->globalSearchable(['name' => new SearchTarget('name')]);
    $this->postJson('/dapter/data/ComposedArray', ['version' => 1])->assertOk()->assertJsonPath('requested.searchMatch', 'all')->assertJsonPath('capabilities.global', ['multi_order' => false, 'multi_order_limit' => 1, 'searchMatch' => ['default' => 'all', 'allowed' => ['all']], 'filters' => null, 'search' => null, 'order' => null]);
});

it('does not advertise composed operations for a custom driver that has not accepted that contract', function (): void {
    composedPrepare('array');
    resolve(DriverRegistry::class)->register('LegacyFixture', static fn (ComposedArray $source): StoreDriver => new class implements StoreDriver
    {
        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(fieldOperations: true);
        }

        public function execute(ValidatedReadPlan $plan): RawPage
        {
            throw new RuntimeException('The unaccepted driver must never execute.');
        }
    });
    ComposedFixture::$options = ComposedFixture::$options->driver('LegacyFixture')->globalFilterable('status', ComposedStatus::class, ComposedStatusFilter::class);
    $this->postJson('/dapter/data/ComposedArray', composedIntent(global: ['filters' => ['status' => ['value' => 'active']]]))->assertStatus(500);
    expect(ComposedFixture::$constructed)->toBe(0)->and(ComposedFixture::$applied)->toBe(0);
});

it('preserves opaque source default ordering with declared identity and an optional fixed prefix', function (string $backend, bool $prefix): void {
    $source = composedPrepare($backend);
    ComposedFixture::$options = ComposedFixture::$options->pageSize(10)->orderableField('rank', valueType: ValueType::Integer)->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)]), $prefix ? [new FixedOrderTerm('rank')] : []));

    if ($backend === 'array') {
        $rows = array_reverse(ComposedFixture::$rows);
        ComposedFixture::$rows = [...array_slice($rows, -1), ...array_slice($rows, 0, -1)];
        $expected = $prefix ? [6, 5, 4, 3, 2, 1] : [1, 6, 5, 4, 3, 2];
    } else {
        ComposedFixture::$builder = (new ComposedRecord)->setConnection(ComposedFixture::$connection)->newQuery()->where('tenant', 1)->orderByDesc('quantity')->orderBy('id');
        $expected = $prefix ? [3, 2, 5, 6, 4, 1] : [3, 2, 5, 6, 1, 4];
    }
    $response = $this->postJson('/dapter/data/'.$source, ['version' => 2])->assertOk()->assertJsonPath('requested.order', ['kind' => 'default'])->assertJsonPath('state.order', ['kind' => 'source'])->assertJsonPath('capabilities.global.stability.identity.0.id', 'id');
    expect(array_column($response->json('data'), 'id'))->toBe($expected)->and($response->json('capabilities.global.stability.prefix'))->toHaveCount($prefix ? 1 : 0);
})->with(composedBackends())->with(['identity' => [false], 'prefix' => [true]]);

it('replaces source fallback order for explicit field ordering and opaque presets with query identity', function (string $backend, string $kind): void {
    $source = composedPrepare($backend);
    ComposedFixture::$rows = array_reverse(ComposedFixture::$rows);

    if ($backend !== 'array') {
        ComposedFixture::$builder = (new ComposedRecord)->setConnection(ComposedFixture::$connection)->newQuery()->where('tenant', 1)->orderByDesc('quantity');
    }
    ComposedFixture::$options = ComposedFixture::$options->pageSize(10)->orderableField('name')->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)])))->globalOrderable('reverse', 'Reverse', static fn (Collection|Builder $rows): Collection|Builder => $rows instanceof Collection ? $rows->sortByDesc('id')->values() : $rows->reorder()->orderByDesc('id'));
    $order = $kind === 'fields' ? ['kind' => 'fields', 'terms' => [['field' => 'name', 'direction' => 'asc', 'sortType' => 'native']]] : ['kind' => 'preset', 'id' => 'reverse'];
    $intent = composedIntent(order: $order);
    $intent['state']['paging']['per_page'] = 10;
    $response = $this->postJson('/dapter/data/'.$source, $intent)->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe($kind === 'fields' ? [1, 3, 2, 4, 5, 6] : [6, 5, 4, 3, 2, 1]);
})->with(composedBackends())->with(['fields', 'preset']);
