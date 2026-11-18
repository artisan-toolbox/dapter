<?php

declare(strict_types=1);

use App\DataSources\LiteralInheritedFolded;
use App\DataSources\LiteralInheritedStrict;
use App\DataSources\OptionRecords;
use App\DataSources\PagingFixture;
use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Drivers\CollectionStoreDriver;
use ArtisanToolbox\Dapter\Drivers\Comparisons\ComparisonPolicy;
use ArtisanToolbox\Dapter\Drivers\Comparisons\ResolvedLiteralSearch;
use ArtisanToolbox\Dapter\Drivers\DriverCapabilities;
use ArtisanToolbox\Dapter\Drivers\NegotiatedRead;
use ArtisanToolbox\Dapter\Drivers\RawPage;
use ArtisanToolbox\Dapter\Drivers\ReadSelection;
use ArtisanToolbox\Dapter\Drivers\SearchClause;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\FullTextOptions;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use ArtisanToolbox\Dapter\Options\SearchTarget;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Pdo\Sqlite;

require_once __DIR__.'/../Fixtures/ListingSources.php';
require_once __DIR__.'/../Fixtures/LiteralSearchSources.php';

it('finds Portuguese case accent and canonical variants by default', function (): void {
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->searchableField('name', [MatchType::Equals]);
    OptionRecords::$rows = array_map(static fn (string $name): array => ['name' => $name], ['Joao', 'JOAO', 'João', 'JOÃO', 'JoÃo', "Joa\u{0303}o"]);
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2, 'state' => ['version' => 2, 'fields' => (object) ['name' => ['search' => ['value' => 'joao', 'matchType' => 'equals']]], 'global' => (object) [], 'searchMatch' => 'all', 'order' => ['kind' => 'default'], 'paging' => ['mode' => 'offset', 'current' => 1, 'per_page' => 10]]])->assertOk()->assertJsonPath('paging.filtered', 6)->assertJsonCount(6, 'data');
});

/** @return array<string,mixed> */
function literalIntent(string $input, MatchType $operator, bool $global = false, PagingMode $mode = PagingMode::Offset, int $current = 1, ?string $cursor = null): array
{
    return ['version' => 2, 'state' => ['version' => 2, 'fields' => (object) ($global ? [] : ['name' => ['search' => ['value' => $input, 'matchType' => $operator->value]]]), 'global' => (object) ($global ? ['search' => ['value' => $input]] : []), 'searchMatch' => 'all', 'order' => ['kind' => 'default'], 'paging' => $mode === PagingMode::Cursor ? ['mode' => 'cursor', 'cursor' => $cursor, 'per_page' => 2] : ['mode' => $mode->value, 'current' => $current, 'per_page' => 2]]];
}

require_once __DIR__.'/../Fixtures/PagingHarness.php';

/** @return array<string,array{string,bool,MatchType,bool}> */
function literalCases(): array
{
    $cases = [];
    foreach (pagingBackends() as $backend => [$engine]) {
        foreach ([false, true] as $strict) {
            foreach ([MatchType::Contains, MatchType::Equals, MatchType::StartsWith, MatchType::EndsWith] as $operator) {
                foreach ([false, true] as $global) {
                    $cases[$backend.' '.($strict ? 'strict' : 'folded').' '.$operator->value.' '.($global ? 'global' : 'field')] = [$engine, $strict, $operator, $global];
                }
            }
        }
    }

    return $cases;
}

/** @return list<array{id:int,tenant:int,name:string,rank:int}> */
function literalRows(): array
{
    $names = ['joao', 'Joao', 'JOAO', 'João', 'JOÃO', 'JoÃo', "Joa\u{0303}o", 'prefix João', 'João suffix', 'prefix João suffix', 'João!%_\\', 'João!x_\\', 'Straße', '東京', 'ø', 'o', 'ł', 'l', ''];
    $rows = [];
    foreach ($names as $index => $name) {
        $rows[] = ['id' => $index + 1, 'tenant' => 1, 'name' => $name, 'rank' => $index + 1];
    }
    $rows[] = ['id' => 90, 'tenant' => 2, 'name' => 'joao', 'rank' => 90];

    return $rows;
}

function prepareLiteral(string $engine, bool $strict, MatchType $operator, bool $global, PagingMode $mode): string
{
    $alias = pagingPrepare($engine, $mode);
    PagingFixture::$rows = literalRows();
    PagingFixture::$options = PagingFixture::$options->strictLiteralSearch($strict)->exactCounts();
    PagingFixture::$options = $global
        ? PagingFixture::$options->globalSearchable(['name' => new SearchTarget('name', matchType: $operator)])
        : PagingFixture::$options->searchableField('name', [$operator]);

    if ($engine !== 'array') {
        $connection = DB::connection(PagingFixture::$connection);
        $connection->table('dapter_phase4_records')->delete();
        $connection->table('dapter_phase4_records')->insert(PagingFixture::$rows);
    }

    return $alias;
}

afterEach(function (): void {
    if (PagingFixture::$rows !== [] && PagingFixture::$connection !== '') {
        DB::connection(PagingFixture::$connection)->getSchemaBuilder()->dropIfExists('dapter_phase4_records');
    }
    PagingFixture::$rows = [];
    PagingFixture::$builder = null;
    PagingFixture::$options = null;
});

it('preserves literal boundaries scopes counts and every paging mode', function (string $engine, bool $strict, MatchType $operator, bool $global): void {
    foreach (PagingMode::cases() as $mode) {
        $alias = prepareLiteral($engine, $strict, $operator, $global, $mode);
        foreach (['joao', "Joa\u{0303}o", 'João!%_\\', 'Straße', '東京'] as $input) {
            $wanted = [];
            foreach (literalRows() as $row) {
                $fold = ResolvedLiteralSearch::fold(...);
                [$value, $needle] = $strict ? [$row['name'], $input] : [$fold($row['name']), $fold($input)];
                $match = match ($operator) {
                    MatchType::Contains => str_contains($value, $needle),
                    MatchType::Equals => $value === $needle,
                    MatchType::StartsWith => str_starts_with($value, $needle),
                    MatchType::EndsWith => str_ends_with($value, $needle),
                    MatchType::FullText => throw new LogicException('Literal fixture cannot use full text.'),
                };

                if ($row['tenant'] === 1 && $match) {
                    $wanted[] = $row['id'];
                }
            }
            $found = [];
            $cursor = null;
            $current = 1;
            do {
                $response = $this->postJson('/dapter/data/'.$alias, literalIntent($input, $operator, $global, $mode, $current, $cursor))->assertOk()->assertJsonPath('paging.total', 19)->assertJsonPath('paging.filtered', count($wanted));
                $found = [...$found, ...array_column($response->json('data'), 'id')];
                $cursor = $response->json('paging.next_cursor');
                $hasMore = $mode === PagingMode::Cursor ? $cursor !== null : ($mode === PagingMode::Simple ? $response->json('paging.has_next') : count($found) < count($wanted));
                $current++;
                expect($current)->toBeLessThan(20);
            } while ($hasMore);
            expect($found)->toBe($wanted);

            if (! $global) {
                $response->assertJsonPath('capabilities.fields.name.search', ['matchTypes' => [$operator->value], 'valueType' => 'string']);
            }
        }
    }
})->with(literalCases());

it('resolves source configuration attributes inheritance and false overrides', function (): void {
    config()->set('dapter.declarations.strict_literal_search', true);
    $resolver = new OptionsResolver;
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    expect($resolver->resolve(new OptionRecords)->strictLiteralSearch)->toBeTrue();
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->strictLiteralSearch(false);
    $parent = new LiteralInheritedStrict;
    expect($resolver->resolve($parent)->strictLiteralSearch)->toBeFalse();
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    expect($resolver->resolve($parent)->strictLiteralSearch)->toBeTrue();
    $child = new LiteralInheritedFolded;
    expect($resolver->resolve($child)->strictLiteralSearch)->toBeFalse();
    OptionRecords::$settings = StoreOptions::makeWithoutDefaults()->publicAccess()->pagingMode(PagingMode::Offset)->pageSize(2)->guard('web');
    expect($resolver->resolve(new OptionRecords)->strictLiteralSearch)->toBeFalse();
});

it('rejects nonboolean strict configuration before creating source data', function (mixed $value): void {
    config()->set('dapter.declarations.strict_literal_search', $value);
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    OptionRecords::$reads = 0;
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
    expect(OptionRecords::$reads)->toBe(0);
})->with([['true'], [1], [null], [[]]]);

it('rejects client policy overrides without changing the wire contract', function (string $placement): void {
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->searchableField('name')->globalSearchable(['name' => new SearchTarget('name')]);
    OptionRecords::$reads = 0;
    $intent = literalIntent('joao', MatchType::Contains, $placement === 'global');

    if ($placement === 'field') {
        $intent['state']['fields']->name['search']['strictLiteralSearch'] = true;
    } elseif ($placement === 'global') {
        $intent['state']['global']->search['strictLiteralSearch'] = true;
    } else {
        $intent['state']['strictLiteralSearch'] = true;
    }
    $this->postJson('/dapter/data/OptionRecords', $intent)->assertStatus(422);
    expect(OptionRecords::$reads)->toBe(0);
})->with(['field', 'global', 'state']);

it('invalidates cursor tokens when source literal policy changes', function (string $engine): void {
    $alias = prepareLiteral($engine, false, MatchType::Contains, false, PagingMode::Cursor);
    $page = $this->postJson('/dapter/data/'.$alias, literalIntent('joao', MatchType::Contains, mode: PagingMode::Cursor))->assertOk();
    PagingFixture::$options = PagingFixture::$options->strictLiteralSearch();
    $this->postJson('/dapter/data/'.$alias, literalIntent('joao', MatchType::Contains, mode: PagingMode::Cursor, cursor: $page->json('paging.next_cursor')))->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
})->with(pagingBackends());

it('keeps typed equality finite filters and native ordering independent', function (bool $strict): void {
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->pageSize(2)->strictLiteralSearch($strict)->searchableField('id', [MatchType::Equals], valueType: ValueType::Integer)->filterableField('name', 'exact', ['João', 'joao'])->orderableField('name');
    OptionRecords::$rows = [['id' => 1, 'name' => 'João'], ['id' => 2, 'name' => 'joao']];
    $intent = literalIntent('unused', MatchType::Equals);
    $intent['state']['fields'] = (object) ['id' => ['search' => ['value' => 1, 'matchType' => 'equals']], 'name' => ['filters' => ['exact' => ['value' => 'joao']]]];
    $this->postJson('/dapter/data/OptionRecords', $intent)->assertOk()->assertJsonPath('paging.filtered', 0);
    $intent['state']['fields'] = (object) ['name' => ['filters' => ['exact' => ['value' => 'João']]]];
    $this->postJson('/dapter/data/OptionRecords', $intent)->assertOk()->assertJsonPath('data.0.id', 1)->assertJsonPath('paging.filtered', 1);
})->with([false, true]);

it('rejects malformed literal source strings before global handlers', function (bool $strict): void {
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->pageSize(2)->strictLiteralSearch($strict)->searchableField('name');
    OptionRecords::$rows = [['name' => "\xff"]];
    $calls = 0;
    OptionRecords::$settings = OptionRecords::$settings->globalFilterable('allowed', [1], static function (Collection $rows, int $value) use (&$calls): Collection {
        $calls++;

        return $rows;
    });
    $intent = literalIntent('joao', MatchType::Contains);
    $intent['state']['global'] = (object) ['filters' => ['allowed' => ['value' => 1]]];
    $this->postJson('/dapter/data/OptionRecords', $intent)->assertStatus(500);
    expect($calls)->toBe(0);
})->with([false, true]);

it('requires explicit custom driver policy awareness and preserves unchanged option identity', function (bool $aware): void {
    $driver = new class($aware) implements StoreDriver
    {
        public int $executions = 0;

        public ?ResolvedStoreOptions $options = null;

        public function __construct(private bool $aware) {}

        public function capabilities(): DriverCapabilities
        {
            return new DriverCapabilities(fieldOperations: true, literalSearchPolicy: $this->aware);
        }

        public function execute(ValidatedReadPlan $plan): RawPage
        {
            $this->executions++;
            $this->options = $plan->options;

            return (new CollectionStoreDriver(collect([['name' => 'João']])))->execute($plan);
        }
    };
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->pageSize(2)->searchableField('name');
    $options = (new OptionsResolver)->resolve(new OptionRecords);
    $selection = new ReadSelection(searches: [new SearchClause('name', 'name', 'joao', MatchType::Contains, ValueType::String)]);

    if (! $aware) {
        expect(fn () => NegotiatedRead::fromDriver($driver, $options, new OffsetRequest(1, 2), $selection))->toThrow(DapterException::class);
        expect($driver->executions)->toBe(0);

        return;
    }
    $page = NegotiatedRead::fromDriver($driver, $options, new OffsetRequest(1, 2), $selection)->execute();
    expect($page->rows)->toBe([['name' => 'João']])->and($driver->options)->toBe($options);
})->with([false, true]);

it('requires no literal prerequisites for numeric or full text only declarations', function (): void {
    $pdo = new class extends PDO
    {
        public function __construct() {}

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            throw new RuntimeException('An unrelated literal prerequisite was queried.');
        }
    };
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->searchableField('id', [MatchType::Equals], valueType: ValueType::Integer);
    $options = (new OptionsResolver)->resolve(new OptionRecords);
    $policy = ComparisonPolicy::sql('sqlite', $pdo);
    expect($policy->resolve($options, new ReadSelection, false)->options)->toBe($options);
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->searchableField('body', [MatchType::FullText], options: new FieldOptions(fullText: new FullTextOptions('pg_catalog.english')));
    $options = (new OptionsResolver)->resolve(new OptionRecords);
    expect($policy->resolve($options, new ReadSelection, false)->literal)->toBeNull();
});

it('fails literal prerequisite registration and false probe results before execution', function (bool $strict): void {
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->strictLiteralSearch($strict)->searchableField('name');
    $options = (new OptionsResolver)->resolve(new OptionRecords);
    $pdo = new class('sqlite::memory:') extends Sqlite
    {
        public function createFunction(string $function_name, callable $callback, int $num_args = -1, int $flags = 0): bool
        {
            return false;
        }

        /** @param array<int,mixed> $options */
        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            return parent::prepare('select 0 as admitted where ? is not null and ? is not null');
        }
    };
    expect(fn () => ComparisonPolicy::sql('sqlite', $pdo)->resolve($options, new ReadSelection))->toThrow(DapterException::class);
})->with([false, true]);

it('keeps pinned mark ranges canonical rather than transliterating letters', function (): void {
    $fold = ResolvedLiteralSearch::fold(...);
    expect($fold('ø ł Æ ß 東京'))->toBe('ø ł æ ß 東京')
        ->and($fold("A\u{1E944}"))->toBe('a')
        ->and($fold("JOA\u{0303}O"))->toBe('joao');
});

it('fails actual SQLite execution PDO admission before handlers counts or rows', function (): void {
    prepareLiteral('sqlite', false, MatchType::Contains, false, PagingMode::Offset);
    $pdo = new class('sqlite::memory:') extends Sqlite
    {
        public function createFunction(string $function_name, callable $callback, int $num_args = -1, int $flags = 0): bool
        {
            return false;
        }
    };
    $connection = DB::connection(PagingFixture::$connection);
    $connection->setPdo($pdo)->setReadPdo($pdo);
    $calls = 0;
    PagingFixture::$options = PagingFixture::$options->globalFilterable('visible', [1], static function (Builder $query, int $value) use (&$calls): Builder {
        $calls++;

        return $query;
    });
    $connection->enableQueryLog();
    $intent = literalIntent('joao', MatchType::Contains);
    $intent['state']['global'] = (object) ['filters' => ['visible' => ['value' => 1]]];
    $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(500)->assertJsonPath('error.code', 'invalid_source');
    expect($calls)->toBe(0)->and($connection->getQueryLog())->toBe([]);
});

it('rejects malformed UTF8 request bytes before source creation', function (): void {
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->searchableField('name');
    OptionRecords::$reads = 0;
    $json = json_encode(literalIntent('INVALID_UTF8', MatchType::Contains), JSON_THROW_ON_ERROR);
    $json = str_replace('INVALID_UTF8', "\xff", $json);
    $this->call('POST', '/dapter/data/OptionRecords', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $json)->assertStatus(400)->assertJsonPath('error.code', 'malformed_json');
    expect(OptionRecords::$reads)->toBe(0);
});

if (getenv('DAPTER_NATIVE_DATABASES') === '1') {
    it('documents native MySQL additional collation equivalences without widening other engines', function (string $engine): void {
        $alias = prepareLiteral($engine, false, MatchType::Equals, false, PagingMode::Offset);
        foreach (['o' => [15, 16], 'l' => [17, 18]] as $input => $mysql) {
            $expected = $engine === 'mysql' ? $mysql : [end($mysql)];
            $this->postJson('/dapter/data/'.$alias, literalIntent($input, MatchType::Equals))->assertOk()->assertJsonPath('paging.filtered', count($expected))->assertJsonPath('data.0.id', $expected[0]);
        }
        $this->postJson('/dapter/data/'.$alias, literalIntent('strasse', MatchType::Equals))->assertOk()->assertJsonPath('paging.filtered', 0);
        PagingFixture::$options = PagingFixture::$options->strictLiteralSearch();
        $this->postJson('/dapter/data/'.$alias, literalIntent('o', MatchType::Equals))->assertOk()->assertJsonPath('paging.filtered', 1)->assertJsonPath('data.0.id', 16);
    })->with(pagingBackends());
}

it('rejects SQL connection execution callbacks for declared literal operations before handlers', function (bool $strict): void {
    prepareLiteral('sqlite', $strict, MatchType::Contains, false, PagingMode::Offset);
    $callbacks = 0;
    $handlers = 0;
    DB::connection(PagingFixture::$connection)->beforeExecuting(static function () use (&$callbacks): void {
        $callbacks++;
    });
    PagingFixture::$options = PagingFixture::$options->globalFilterable('visible', [1], static function (Builder $query, int $value) use (&$handlers): Builder {
        $handlers++;

        return $query;
    });
    $intent = literalIntent('', MatchType::Contains);
    $intent['state']['global'] = (object) ['filters' => ['visible' => ['value' => 1]]];
    $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(500)->assertJsonPath('error.code', 'invalid_source');
    expect($callbacks)->toBe(0)->and($handlers)->toBe(0);
})->with([false, true]);

it('keeps a nonempty combining-mark request active after folding to an empty literal', function (string $engine, bool $strict, MatchType $operator, bool $global): void {
    foreach (PagingMode::cases() as $mode) {
        $alias = prepareLiteral($engine, $strict, $operator, $global, $mode);
        PagingFixture::$rows = [
            ['id' => 1, 'tenant' => 1, 'name' => '', 'rank' => 1],
            ['id' => 2, 'tenant' => 1, 'name' => "\u{0301}", 'rank' => 2],
            ['id' => 3, 'tenant' => 1, 'name' => 'other', 'rank' => 3],
            ['id' => 4, 'tenant' => 1, 'name' => null, 'rank' => 4],
            ['id' => 5, 'tenant' => 2, 'name' => "\u{0301}", 'rank' => 5],
        ];

        if ($engine !== 'array') {
            $connection = DB::connection(PagingFixture::$connection);
            $connection->getSchemaBuilder()->table('dapter_phase4_records', static function (Blueprint $table): void {
                $table->string('name')->nullable()->change();
            });
            $connection->table('dapter_phase4_records')->delete();
            $connection->table('dapter_phase4_records')->insert(PagingFixture::$rows);
        }
        $expected = $strict ? [2] : ($operator === MatchType::Equals ? [1, 2] : [1, 2, 3]);
        $found = [];
        $current = 1;
        $cursor = null;
        do {
            $page = $this->postJson('/dapter/data/'.$alias, literalIntent("\u{0301}", $operator, $global, $mode, $current, $cursor))->assertOk()->assertJsonPath('paging.total', 4)->assertJsonPath('paging.filtered', count($expected));
            $page->assertJsonPath($global ? 'requested.global.search.value' : 'requested.fields.name.search.value', "\u{0301}");
            $found = [...$found, ...array_column($page->json('data'), 'id')];
            $cursor = $page->json('paging.next_cursor');
            $hasMore = $mode === PagingMode::Cursor ? $cursor !== null : ($mode === PagingMode::Simple ? $page->json('paging.has_next') : count($found) < count($expected));
            $current++;
            expect($current)->toBeLessThan(5);
        } while ($hasMore);
        expect($found)->toBe($expected);
    }
})->with(literalCases());
