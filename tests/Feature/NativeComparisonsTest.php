<?php

declare(strict_types=1);

use App\DataSources\PagingFixture;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\FixedOrderTerm;
use ArtisanToolbox\Dapter\Options\FullTextOptions;
use ArtisanToolbox\Dapter\Options\LocaleOptions;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\SearchTarget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Fixtures/PagingHarness.php';

/** @return array<string,array{string}> */
function comparisonSqlBackends(): array
{
    return getenv('DAPTER_NATIVE_DATABASES') === '1' ? ['SQLite' => ['sqlite'], 'MySQL' => ['mysql'], 'PostgreSQL' => ['pgsql']] : ['SQLite' => ['sqlite']];
}

/** @return array<string,mixed> */
function fullTextIntent(string $value = 'cats running', PagingMode $mode = PagingMode::Offset, ?string $cursor = null): array
{
    return ['version' => 2, 'state' => ['version' => 2, 'fields' => (object) ['body' => ['search' => ['value' => $value, 'matchType' => 'full_text']]], 'global' => (object) [], 'searchMatch' => 'all', 'order' => ['kind' => 'default'], 'paging' => $mode === PagingMode::Cursor ? ['mode' => 'cursor', 'cursor' => $cursor, 'per_page' => 2] : ['mode' => $mode->value, 'current' => 1, 'per_page' => 2]]];
}

function prepareComparisonSql(string $backend, PagingMode $mode = PagingMode::Offset, string $configuration = 'pg_catalog.english'): void
{
    pagingPrepare($backend, $mode);
    DB::connection(PagingFixture::$connection)->getSchemaBuilder()->table('dapter_phase4_records', static fn (Blueprint $table) => $table->text('body')->nullable());
    $connection = DB::connection(PagingFixture::$connection);
    foreach ([1 => 'The cats are running', 2 => 'A running cat and dogs', 3 => 'Cats running', 4 => 'dogs only', 9 => 'Cats running'] as $id => $body) {
        $connection->table('dapter_phase4_records')->where('id', $id)->update(['body' => $body]);
    }
    PagingFixture::$options = PagingFixture::$options->searchableField('body', [MatchType::Contains, MatchType::FullText], options: new FieldOptions(fullText: new FullTextOptions($configuration)))->exactCounts();
}

afterEach(function (): void {
    if (PagingFixture::$rows !== []) {
        DB::connection(PagingFixture::$connection)->getSchemaBuilder()->dropIfExists('dapter_phase4_records');
    }
    PagingFixture::$rows = [];
    PagingFixture::$builder = null;
    PagingFixture::$options = null;
});

it('admits full text only on PostgreSQL and omits native SQL locale ordering', function (string $backend): void {
    prepareComparisonSql($backend);
    PagingFixture::$options = PagingFixture::$options->orderableField('title', [SortType::Native, SortType::Locale], target: 'name', valueType: ValueType::String, options: new FieldOptions(locale: new LocaleOptions('sv')));
    $response = $this->postJson('/dapter/data/PagingSql', ['version' => 2])->assertOk()->assertJsonPath('capabilities.fields.title.order.sortTypes', ['native'])->assertJsonMissingPath('capabilities.fields.title.order.locale');
    $response->assertJsonPath('capabilities.fields.body.search.matchTypes', $backend === 'pgsql' ? ['contains', 'full_text'] : ['contains']);
    $selected = $this->postJson('/dapter/data/PagingSql', fullTextIntent());

    if ($backend === 'pgsql') {
        $selected->assertOk()->assertJsonPath('paging.total', 5)->assertJsonPath('paging.filtered', 3);
        expect(array_column($selected->json('data'), 'id'))->toBe([1, 2]);
    } else {
        $selected->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation');
    }
})->with(comparisonSqlBackends());

it('keeps an unavailable SQL locale prefix a configuration failure when also requested', function (): void {
    prepareComparisonSql('sqlite');
    PagingFixture::$options = PagingFixture::$options->orderableField('title', [SortType::Locale], target: 'name', valueType: ValueType::String, options: new FieldOptions(locale: new LocaleOptions('sv')))
        ->query(new QueryOptions(fixedOrder: [new FixedOrderTerm('title', sortType: SortType::Locale)]));
    $intent = fullTextIntent('');
    $intent['state']['order'] = ['kind' => 'fields', 'terms' => [['field' => 'title', 'direction' => 'asc', 'sortType' => 'locale']]];
    $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(500)->assertJsonPath('error.code', 'invalid_source');
});

if (getenv('DAPTER_NATIVE_DATABASES') === '1') {
    it('uses native PostgreSQL plain text semantics and exact scoped counts for all paging modes', function (PagingMode $mode): void {
        prepareComparisonSql('pgsql', $mode);
        $page = $this->postJson('/dapter/data/PagingSql', fullTextIntent(mode: $mode))->assertOk()->assertJsonPath('paging.total', 5)->assertJsonPath('paging.filtered', 3)
            ->assertJsonPath('capabilities.fields.body.search.fullText', ['engine' => 'pgsql', 'configuration' => 'pg_catalog.english', 'input' => 'plain_text', 'maxBytes' => 512, 'ranking' => 'filter_only']);
        expect(array_column($page->json('data'), 'id'))->toBe([1, 2]);
        $this->postJson('/dapter/data/PagingSql', fullTextIntent('the and', $mode))->assertOk()->assertJsonPath('paging.filtered', 0)->assertJsonCount(0, 'data');
        $this->postJson('/dapter/data/PagingSql', fullTextIntent('cats | dogs', $mode))->assertOk()->assertJsonPath('paging.filtered', 1)->assertJsonPath('data.0.id', 2);
        $this->postJson('/dapter/data/PagingSql', fullTextIntent('', $mode))->assertOk()->assertJsonPath('paging.filtered', 5);

        if ($mode === PagingMode::Cursor) {
            $token = $page->json('paging.next_cursor');
            $next = $this->postJson('/dapter/data/PagingSql', fullTextIntent(mode: $mode, cursor: $token))->assertOk()->assertJsonPath('data.0.id', 3)->assertJsonCount(1, 'data');
            $this->postJson('/dapter/data/PagingSql', fullTextIntent(mode: $mode, cursor: $next->json('paging.previous_cursor')))->assertOk()->assertJsonPath('data.0.id', 1);
            prepareComparisonSql('pgsql', $mode, 'pg_catalog.simple');
            $this->postJson('/dapter/data/PagingSql', fullTextIntent(mode: $mode, cursor: $token))->assertStatus(422)->assertJsonPath('error.code', 'invalid_cursor');
        }
    })->with([PagingMode::Offset, PagingMode::Simple, PagingMode::Cursor]);

    it('uses the actual split read or write PDO for probes counts and rows', function (bool $write): void {
        prepareComparisonSql('pgsql');
        $connection = DB::connection(PagingFixture::$connection);
        $configuration = $connection->getConfig();
        $dsn = 'pgsql:host='.$configuration['host'].';port='.$configuration['port'].';dbname='.$configuration['database'];
        $read = new ComparisonRecordingPdo($dsn, $configuration['username'], $configuration['password']);
        $writer = new ComparisonRecordingPdo($dsn, $configuration['username'], $configuration['password']);
        $connection->setPdo($writer)->setReadPdo($read);
        $connection->forgetRecordModificationState();
        PagingFixture::$builder = pagingBuilder();

        if ($write) {
            PagingFixture::$builder->useWritePdo();
        }
        $writer->beginTransaction();

        try {
            $writer->exec("update dapter_phase4_records set body = 'dogs only' where id = 1");
            $this->postJson('/dapter/data/PagingSql', fullTextIntent())->assertOk()->assertJsonPath('paging.filtered', $write ? 2 : 3)->assertJsonPath('data.0.id', $write ? 2 : 1);
            $selected = $write ? $writer : $read;
            $other = $write ? $read : $writer;
            expect(count(array_filter($selected->statements, static fn (string $sql): bool => str_contains($sql, 'pg_catalog.pg_ts_config'))))->toBe(1)
                ->and(count(array_filter($selected->statements, static fn (string $sql): bool => str_contains($sql, 'to_tsvector'))))->toBeGreaterThanOrEqual(3)
                ->and(array_filter($other->statements, static fn (string $sql): bool => str_contains($sql, 'to_tsvector') || str_contains($sql, 'pg_catalog.pg_ts_config')))->toBe([]);
        } finally {
            $writer->rollBack();
        }
    })->with([false, true]);

    it('reports catalog and expression failures before handlers instead of treating them as absence', function (string $failure): void {
        prepareComparisonSql('pgsql');
        $connection = DB::connection(PagingFixture::$connection);
        $configuration = $connection->getConfig();
        $pdo = new ComparisonRecordingPdo('pgsql:host='.$configuration['host'].';port='.$configuration['port'].';dbname='.$configuration['database'], $configuration['username'], $configuration['password']);
        $pdo->failure = $failure;
        $connection->setReadPdo($pdo);
        PagingFixture::$options = PagingFixture::$options->globalFilterable('visible', [1], static function (Builder $query, int $value): Builder {
            PagingFixture::$counts++;

            return $query;
        });
        $intent = fullTextIntent();
        $intent['state']['global'] = (object) ['filters' => ['visible' => ['value' => 1]]];
        $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(500);
        expect(PagingFixture::$counts)->toBe(0)->and(array_filter($pdo->statements, static fn (string $sql): bool => str_contains($sql, 'dapter_phase4_records')))->toBe([]);
    })->with(['catalog', 'expression']);

    it('rejects handler changes to effective read routing before counts or rows', function (string $routing): void {
        prepareComparisonSql('pgsql');
        $connection = DB::connection(PagingFixture::$connection);
        $configuration = $connection->getConfig();
        $read = new ComparisonRecordingPdo('pgsql:host='.$configuration['host'].';port='.$configuration['port'].';dbname='.$configuration['database'], $configuration['username'], $configuration['password']);
        $connection->setReadPdo($read);
        PagingFixture::$options = PagingFixture::$options->globalFilterable('visible', [1], static function (Builder $query, int $value) use ($routing, $connection): Builder {
            if ($routing === 'write') {
                $query->useWritePdo();
            } elseif ($routing === 'transaction') {
                $connection->beginTransaction();
            } elseif ($routing === 'search_path') {
                $connection->getReadPdo()->exec('set search_path to pg_catalog');
            } elseif ($routing === 'role') {
                $connection->getReadPdo()->exec('set role pg_read_all_data');
            } else {
                $connection->useWriteConnectionWhenReading();
            }

            return $query;
        });
        $intent = fullTextIntent();
        $intent['state']['global'] = (object) ['filters' => ['visible' => ['value' => 1]]];

        try {
            $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(500);
            expect(array_filter($read->statements, static fn (string $sql): bool => str_contains($sql, 'dapter_phase4_records')))->toBe([]);
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            $connection->useWriteConnectionWhenReading(false);
            $read->exec('reset role');
            $read->exec('reset search_path');
        }
    })->with(['write', 'sticky', 'transaction', 'role', 'search_path']);

    it('rejects late connection callbacks before admitted full text can count or read', function (bool $late): void {
        prepareComparisonSql('pgsql');
        $connection = DB::connection(PagingFixture::$connection);
        $install = static function () use ($connection): void {
            $connection->beforeExecuting(static function (): void {
                PagingFixture::$counts++;
            });
        };

        if (! $late) {
            $install();
        }
        PagingFixture::$options = PagingFixture::$options->globalFilterable('visible', [1], static function (Builder $query, int $value) use ($install, $late): Builder {
            if ($late) {
                $install();
            }

            return $query;
        });
        $intent = fullTextIntent();
        $intent['state']['global'] = (object) ['filters' => ['visible' => ['value' => 1]]];
        $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(500);
        expect(PagingFixture::$counts)->toBe(0);
    })->with([false, true]);

    it('keeps fixed global full text targets server owned', function (): void {
        prepareComparisonSql('pgsql');
        PagingFixture::$options = PagingFixture::$options->globalSearchable(['article' => new SearchTarget('body', matchType: MatchType::FullText, fullText: new FullTextOptions('pg_catalog.english'))]);
        $intent = fullTextIntent();
        $intent['state']['fields'] = (object) [];
        $intent['state']['global'] = (object) ['search' => ['value' => 'cats running']];
        $this->postJson('/dapter/data/PagingSql', $intent)->assertOk()->assertJsonPath('paging.filtered', 3)->assertJsonPath('capabilities.global.search.targets.article.fullText.configuration', 'pg_catalog.english');
    });

    it('distinguishes absent optional text configurations from required fixed targets before handlers', function (): void {
        prepareComparisonSql('pgsql', configuration: 'pg_catalog.dapter_missing');
        $this->postJson('/dapter/data/PagingSql', ['version' => 2])->assertOk()->assertJsonPath('capabilities.fields.body.search.matchTypes', ['contains']);
        PagingFixture::$options = PagingFixture::$options->globalFilterable('visible', [1], static function (Builder $query, int $value): Builder {
            PagingFixture::$counts++;

            return $query;
        });
        $intent = fullTextIntent();
        $intent['state']['global'] = (object) ['filters' => ['visible' => ['value' => 1]]];
        $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation');
        expect(PagingFixture::$counts)->toBe(0);
        PagingFixture::$options = PagingFixture::$options->globalSearchable(['article' => new SearchTarget('body', matchType: MatchType::FullText, fullText: new FullTextOptions('pg_catalog.dapter_missing'))]);
        $this->postJson('/dapter/data/PagingSql', ['version' => 2])->assertStatus(500);
    });
}

final class ComparisonRecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    public ?string $failure = null;

    /** @param array<int,mixed> $options */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->statements[] = $query;

        throw_if($this->failure === 'catalog' && str_contains($query, 'pg_catalog.pg_ts_config'), PDOException::class, 'The catalog probe was denied.');

        if ($this->failure === 'expression' && str_contains($query, 'to_tsvector')) {
            return parent::prepare('select dapter_phase6_missing_function()', $options);
        }

        return parent::prepare($query, $options);
    }
}

if (getenv('DAPTER_NATIVE_DATABASES') === '1') {
    it('keeps PostgreSQL full text dictionary behavior under either literal policy', function (bool $strict): void {
        prepareComparisonSql('pgsql');
        PagingFixture::$options = PagingFixture::$options->strictLiteralSearch($strict);
        $this->postJson('/dapter/data/PagingSql', fullTextIntent('CATS RUNNING'))->assertOk()->assertJsonPath('paging.filtered', 3)->assertJsonPath('capabilities.fields.body.search.fullText.configuration', 'pg_catalog.english');
        $literal = fullTextIntent('CATS RUNNING');
        $literal['state']['fields']->body['search']['matchType'] = 'contains';
        $this->postJson('/dapter/data/PagingSql', $literal)->assertOk()->assertJsonPath('paging.filtered', $strict ? 0 : 1);
    })->with([false, true]);
}
