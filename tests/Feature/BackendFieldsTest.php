<?php

declare(strict_types=1);

use App\DataSources\CollidingBackendFields;
use App\DataSources\InheritedBackendFields;
use App\DataSources\OptionRecords;
use App\DataSources\RemovedBackendFields;
use App\DataSources\ReplacedBackendFields;
use ArtisanToolbox\Dapter\Contracts\CollectionSource;
use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\EloquentSource;
use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Drivers\CollectionStoreDriver;
use ArtisanToolbox\Dapter\Drivers\DriverCapabilities;
use ArtisanToolbox\Dapter\Drivers\DriverRegistry;
use ArtisanToolbox\Dapter\Drivers\RawPage;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;
use ArtisanToolbox\Dapter\Enums\EmptySelectionPolicy;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\StoreOptions;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

require_once __DIR__.'/../Fixtures/ListingSources.php';
require_once __DIR__.'/../Fixtures/BackendFieldSources.php';

beforeEach(function (): void {
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    OptionRecords::$reads = 0;
    OptionRecords::$rows = [];
});

/**
 * @param  array<string,mixed>  $fields
 * @param  array<string,mixed>|null  $order
 * @return array<string,mixed>
 */
function backendFieldRequest(array $fields = [], ?array $order = null, int $current = 1): array
{
    return ['version' => 2, 'state' => ['version' => 2, 'fields' => (object) $fields, 'global' => (object) [], 'searchMatch' => 'all', 'order' => $order ?? ['kind' => 'default'], 'paging' => ['mode' => 'offset', 'current' => $current, 'per_page' => 5]]];
}

it('layers attributes with whole-entry replacement removals and collision rejection', function (): void {
    $resolver = new OptionsResolver;
    expect($resolver->resolve(new InheritedBackendFields)->fields['name']->search->matchTypes)->toBe([MatchType::Contains, MatchType::Equals])
        ->and($resolver->resolve(new ReplacedBackendFields)->fields['name']->search->matchTypes)->toBe([MatchType::Equals])
        ->and($resolver->resolve(new RemovedBackendFields)->fields)->toBe([])
        ->and(fn () => $resolver->resolve(new CollidingBackendFields))->toThrow(DapterException::class);
    $original = StoreOptions::make();
    $changed = $original->searchableField('name');
    expect($original->fieldSettings())->toBe([])->and($changed->fieldSettings())->toHaveCount(1)
        ->and(fn () => $changed->withoutFieldSearch('name'))->toThrow(DapterException::class);
});

it('filters null-only declarations without coercing nonnull primitive families', function (bool $objects): void {
    OptionRecords::$settings = OptionRecords::$settings->filterableField('value', 'null', [null], empty: EmptySelectionPolicy::Reject);
    $rows = [['value' => 0], ['value' => false], ['value' => null], ['value' => '']];
    OptionRecords::$rows = $objects ? array_map(static fn (array $row): stdClass => (object) $row, $rows) : $rows;
    $this->postJson('/dapter/data/OptionRecords', backendFieldRequest(['value' => ['filters' => ['null' => ['value' => null]]]]))
        ->assertOk()->assertJsonPath('data', [['value' => null]])->assertJsonPath('paging.total', 4)->assertJsonPath('paging.filtered', 1);
})->with(['arrays' => false, 'objects' => true]);

it('distinguishes a present nested null from a missing object target', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->filterableField('value', 'nullable', [null, 'one'], target: 'profile.value');
    OptionRecords::$rows = [(object) ['profile' => (object) ['value' => null]], (object) ['profile' => (object) ['value' => 'one']]];
    $this->postJson('/dapter/data/OptionRecords', backendFieldRequest(['value' => ['filters' => ['nullable' => ['value' => null]]]]))
        ->assertOk()->assertJsonPath('data', [['profile' => ['value' => null]]])->assertJsonPath('paging.total', 2)->assertJsonPath('paging.filtered', 1);
    OptionRecords::$rows = [(object) ['profile' => (object) []]];
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500)->assertJsonPath('error.code', 'invalid_source');
});

class BackendFieldIdResource extends JsonResource
{
    /** @return array{id:int} */
    public function toArray(Request $request): array
    {
        return ['id' => $this->resource['id']];
    }
}

it('keeps Laravel lookup precedence and rejects inaccessible or uninitialized object fields', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->useResource(BackendFieldIdResource::class)
        ->filterableField('value', 'selected', [null, 'offset', 'magic'], target: 'profile.value');
    $offset = new class(['value' => 'offset']) extends ArrayObject
    {
        public ?string $value = null;
    };
    $magic = new class
    {
        public function __isset(string $name): bool
        {
            return $name === 'value';
        }

        public function __get(string $name): string
        {
            return 'magic';
        }
    };
    OptionRecords::$rows = [['id' => 1, 'profile' => $offset], ['id' => 2, 'profile' => $magic]];
    foreach (['offset' => 1, 'magic' => 2] as $needle => $id) {
        $this->postJson('/dapter/data/OptionRecords', backendFieldRequest(['value' => ['filters' => ['selected' => ['value' => $needle]]]]))
            ->assertOk()->assertJsonPath('data', [['id' => $id]]);
    }
    $private = new class('offset') implements Stringable
    {
        public function __construct(private string $value) {}

        public function __toString(): string
        {
            return $this->value;
        }
    };
    $uninitialized = new class
    {
        public ?string $value;
    };
    foreach ([$private, $uninitialized, null] as $profile) {
        OptionRecords::$rows = [['id' => 1, 'profile' => $profile]];
        $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500)->assertJsonPath('error.code', 'invalid_source');
    }
});

it('orders tied identity-free rows by stable input ordinal across pages', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->orderableField('score', valueType: ValueType::Integer);
    OptionRecords::$rows = array_map(static fn (int $index): array => ['label' => 'Row '.$index, 'score' => 1], range(1, 8));
    $order = ['kind' => 'fields', 'terms' => [['field' => 'score', 'direction' => 'desc', 'sortType' => 'native']]];
    $this->postJson('/dapter/data/OptionRecords', backendFieldRequest(order: $order))->assertOk()->assertJsonPath('data.4.label', 'Row 5');
    $this->postJson('/dapter/data/OptionRecords', backendFieldRequest(order: $order, current: 2))->assertOk()->assertJsonPath('data.0.label', 'Row 6')->assertJsonPath('data.2.label', 'Row 8');
});

it('validates all declared collection targets and row domains before filtering', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->searchableField('value', [MatchType::Equals], valueType: ValueType::Integer);
    OptionRecords::$rows = [['value' => 1], ['value' => '1']];
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
    OptionRecords::$rows = [['other' => 1]];
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
});

it('rejects invalid final clauses before calling the source', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->searchableField('value', [MatchType::Equals], valueType: ValueType::Integer)->filterableField('value', 'allowed', [0, 1]);
    $this->postJson('/dapter/data/OptionRecords', backendFieldRequest(['value' => ['search' => ['value' => 1, 'matchType' => 'equals'], 'filters' => ['allowed' => ['value' => '1']]]]))->assertStatus(422);
    expect(OptionRecords::$reads)->toBe(0);
});

it('rejects nonnull selections for a null-only filter before source work', function (mixed $value, bool $multiple): void {
    OptionRecords::$settings = OptionRecords::$settings->filterableField('value', 'empty', [null], multiple: $multiple);
    $selection = $multiple ? [$value] : $value;

    $this->postJson('/dapter/data/OptionRecords', backendFieldRequest(['value' => ['filters' => ['empty' => ['value' => $selection]]]]))
        ->assertStatus(422)->assertJsonPath('error.kind', 'validation');

    expect(OptionRecords::$reads)->toBe(0);
})->with([0, false, '', '0'])->with([false, true]);

it('registers exact source aliases and refuses duplicates and case-fold collisions', function (): void {
    $registry = resolve(SourceRegistry::class);
    $registry->register('PublicAlias', OptionRecords::class);
    $this->postJson('/dapter/data/PublicAlias', ['version' => 2])->assertOk();
    $this->postJson('/dapter/data/publicalias', ['version' => 2])->assertNotFound();
    expect(fn () => $registry->register('PublicAlias', OptionRecords::class))->toThrow(DapterException::class)
        ->and(fn () => $registry->register('publicalias', OptionRecords::class))->toThrow(DapterException::class)
        ->and(fn () => $registry->register('class', OptionRecords::class))->toThrow(DapterException::class);
});

it('selects a trusted source-bound driver alias without fallback', function (): void {
    $registry = resolve(DriverRegistry::class);
    $registry->register('fixture', static fn (CollectionSource $source): StoreDriver => new CollectionStoreDriver(collect($source->source())));
    OptionRecords::$settings = OptionRecords::$settings->driver('fixture');
    OptionRecords::$rows = [['value' => 'bound']];
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertOk()->assertJsonPath('data.0.value', 'bound');
    expect(fn () => $registry->register('FIXTURE', static fn (DataSource $source): StoreDriver => throw DapterException::configuration()))->toThrow(DapterException::class);
    OptionRecords::$settings = OptionRecords::$settings->driver('missing');
    OptionRecords::$reads = 0;
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
    expect(OptionRecords::$reads)->toBe(0);
    $registry->register('wrong', static fn (EloquentSource $source): StoreDriver => throw DapterException::configuration());
    expect(fn () => $registry->make('wrong', new OptionRecords))->toThrow(DapterException::class);
});

it('does not grant managed support to a custom offset-only driver', function (): void {
    $driver = new class implements StoreDriver
    {
        public int $executions = 0;

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(offset: true);
        }

        public function execute(ValidatedReadPlan $plan): RawPage
        {
            $this->executions++;

            throw DapterException::configuration();
        }
    };
    resolve(DriverRegistry::class)->register('offset_only', static fn (DataSource $source): StoreDriver => $driver);
    OptionRecords::$settings = OptionRecords::$settings->driver('offset_only')->searchableField('value');
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
    expect($driver->executions)->toBe(0);
});

it('rejects ambiguous declared options unsafe numbers target conflicts and unsupported sorts', function (): void {
    foreach ([
        StoreOptions::make()->publicAccess()->filterableField('value', 'mixed', [1, 1.5]),
        StoreOptions::make()->publicAccess()->filterableField('value', 'unsafe', [9007199254740992]),
        StoreOptions::make()->publicAccess()->searchableField('value', target: 'a')->orderableField('value', target: 'b'),
        StoreOptions::make()->publicAccess()->searchableField('value', [MatchType::Equals], valueType: ValueType::Boolean),
        StoreOptions::make()->publicAccess()->orderableField('value', [SortType::Locale]),
    ] as $options) {
        OptionRecords::$settings = $options;
        $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
    }
});

it('rejects numeric token underflow and overflow before source evaluation', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->searchableField('value', [MatchType::Equals], valueType: ValueType::Number);
    foreach (['1e-400', '1e400', '9007199254740992'] as $number) {
        $request = '{"version":2,"state":{"version":2,"fields":{"value":{"search":{"value":'.$number.',"matchType":"equals"}}},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":1,"per_page":5}}}';
        $this->call('POST', '/dapter/data/OptionRecords', server: ['CONTENT_TYPE' => 'application/json'], content: $request)->assertStatus(422)->assertJsonPath('version', 2);
    }
    expect(OptionRecords::$reads)->toBe(0);
});

it('preserves explicit number filter members through field and global enum normalization', function (string $scope, int|float $value, int $id): void {
    OptionRecords::$rows = [['id' => 1, 'value' => 0], ['id' => 2, 'value' => 1.5], ['id' => 3, 'value' => 2], ['id' => 4, 'value' => 10]];
    $request = backendFieldRequest();

    if ($scope === 'field') {
        OptionRecords::$settings = OptionRecords::$settings->filterableField('value', 'number', [0, 1.5, 2, 10], valueType: ValueType::Number);
        $request = backendFieldRequest(['value' => ['filters' => ['number' => ['value' => $value]]]]);
    } else {
        OptionRecords::$settings = OptionRecords::$settings->globalFilterable('number', [0, 1.5, 2, 10],
            /** @param Collection<int,array<string,mixed>> $rows
             * @return Collection<int,array<string,mixed>>
             */
            static fn (Collection $rows, float $selected): Collection => $rows->filter(static fn (array $row): bool => (float) $row['value'] === $selected),
            valueType: ValueType::Number);
        $request['state']['global'] = ['filters' => ['number' => ['value' => $value]]];
    }
    $response = $this->postJson('/dapter/data/OptionRecords', $request)->assertOk()->assertJsonPath('paging.filtered', 1);
    expect(array_column($response->json('data'), 'id'))->toBe([$id]);
    expect(OptionRecords::$reads)->toBe(1);
})->with(['field', 'global'])->with(['zero' => [0, 1], 'fraction' => [1.5, 2], 'integer' => [2, 3], 'ten' => [10, 4]]);

it('filters a nullable property without evaluating unrelated property hooks', function (string $kind): void {
    $profile = match ($kind) {
        'backed' => new class
        {
            public ?string $value {
                get => $this->value ?? null;
            }

            public int $captionReads = 0;

            public string $caption {
                get {
                    $this->captionReads++;

                    throw new LogicException('The caption must not be loaded for this filter.');
                }
            }
        },
        'virtual' => new class
        {
            public ?string $value {
                get => null;
            }

            public int $captionReads = 0;

            public string $caption {
                get {
                    $this->captionReads++;

                    throw new LogicException('The caption must not be loaded for this filter.');
                }
            }
        },
        default => new class
        {
            public ?string $value = null;

            public int $captionReads = 0;

            public string $caption {
                get {
                    $this->captionReads++;

                    throw new LogicException('The caption must not be loaded for this filter.');
                }
            }
        },
    };
    OptionRecords::$settings = OptionRecords::$settings->useResource(BackendFieldIdResource::class)
        ->filterableField('value', 'nullable', [null], target: 'profile.value');
    OptionRecords::$rows = [['id' => 17, 'profile' => $profile]];
    $this->postJson('/dapter/data/OptionRecords', backendFieldRequest(['value' => ['filters' => ['nullable' => ['value' => null]]]]))
        ->assertOk()->assertJsonPath('data', [['id' => 17]])->assertJsonPath('paging.filtered', 1);
    expect($profile->captionReads)->toBe(0);
})->with(['stored property' => 'stored', 'virtual property' => 'virtual', 'backed property without initialized storage' => 'backed']);
