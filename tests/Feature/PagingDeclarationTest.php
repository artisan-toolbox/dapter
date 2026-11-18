<?php

declare(strict_types=1);

use App\DataSources\KernelCountedRecords;
use App\DataSources\KernelDuplicateCounts;
use App\DataSources\KernelNoScopeRecords;
use App\DataSources\KernelPagingRecords;
use App\DataSources\KernelUncountedRecords;
use ArtisanToolbox\Dapter\Contracts\CollectionSource;
use ArtisanToolbox\Dapter\Contracts\EloquentSource;
use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Drivers\DriverCapabilities;
use ArtisanToolbox\Dapter\Drivers\DriverRegistry;
use ArtisanToolbox\Dapter\Drivers\RawPage;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\ReadIntent;
use ArtisanToolbox\Dapter\Http\SimpleRequest;
use ArtisanToolbox\Dapter\Options\IdentityColumn;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\ResultIdentity;
use ArtisanToolbox\Dapter\StoreOptions;
use ArtisanToolbox\Dapter\Support\SourceRegistry;

require_once __DIR__.'/../Fixtures/KernelPagingSources.php';

/**
 * @param  array<string,mixed>  $paging
 * @return array<string,mixed>
 */
function kernelPagingIntent(array $paging): array
{
    return ['version' => 2, 'state' => ['version' => 2, 'fields' => new stdClass, 'global' => new stdClass, 'searchMatch' => 'all', 'order' => ['kind' => 'default'], 'paging' => $paging]];
}

beforeEach(function (): void {
    KernelPagingRecords::$settings = null;
    KernelPagingRecords::$reads = 0;
    KernelPagingRecords::$scopes = 0;
    KernelPagingRecords::$scope = 'public-test-v1';
    resolve(SourceRegistry::class)->register('KernelPagingRecords', KernelPagingRecords::class);
});

it('captures count policy defaults and supports immutable fluent attribute precedence', function (): void {
    config()->set('dapter.paging.exact_counts', true);
    $captured = StoreOptions::make()->publicAccess();
    config()->set('dapter.paging.exact_counts', false);
    KernelPagingRecords::$settings = $captured;
    $resolver = new OptionsResolver;
    expect($resolver->resolve(new KernelPagingRecords)->exactCounts)->toBeTrue();
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess();
    expect($resolver->resolve(new KernelCountedRecords)->exactCounts)->toBeTrue()
        ->and($resolver->resolve(new KernelUncountedRecords)->exactCounts)->toBeFalse();
    KernelPagingRecords::$settings = $captured->exactCounts(false);
    expect($resolver->resolve(new KernelCountedRecords)->exactCounts)->toBeFalse();
    KernelPagingRecords::$settings = StoreOptions::makeWithoutDefaults()->publicAccess()->guard('web')->pagingMode(PagingMode::Simple)->pageSize(5);
    config()->set('dapter.paging.exact_counts', true);
    expect($resolver->resolve(new KernelPagingRecords)->exactCounts)->toBeFalse();
    expect(fn () => $resolver->resolve(new KernelDuplicateCounts))->toThrow(DapterException::class);
});

it('discovers one advanced server mode only through protocol two', function (PagingMode $mode): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode($mode)->pageSize(2)
        ->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)])));
    $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 1])->assertStatus(422);
    expect(KernelPagingRecords::$reads)->toBe(0)->and(KernelPagingRecords::$scopes)->toBe(0);
    $response = $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 2])->assertOk()
        ->assertJsonPath('capabilities.paging.mode', $mode->value)->assertJsonPath('capabilities.paging.counts', 'unknown')
        ->assertJsonPath('paging.pages', null)->assertJsonPath('paging.total', null)->assertJsonPath('paging.filtered', null)->assertJsonCount(2, 'data');

    if ($mode === PagingMode::Cursor) {
        $response->assertJsonPath('capabilities.paging.expires_after', 900)->assertJsonPath('paging.current', null)->assertJsonPath('requested.paging.cursor', null);
    } else {
        $response->assertJsonPath('requested.paging.current', 1)->assertJsonPath('paging.previous_cursor', null)->assertJsonPath('paging.next_cursor', null);
    }
})->with([PagingMode::Simple, PagingMode::Cursor]);

it('rejects mismatched mode before source and scope acquisition', function (): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor);
    $this->postJson('/dapter/data/KernelPagingRecords', kernelPagingIntent(['mode' => 'simple', 'current' => 1, 'per_page' => 5]))->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation');
    expect(KernelPagingRecords::$reads)->toBe(0)->and(KernelPagingRecords::$scopes)->toBe(0);
});

it('validates complete criteria before the cursor scope hook', function (): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor);
    $intent = kernelPagingIntent(['mode' => 'cursor', 'cursor' => null, 'per_page' => 5]);
    $intent['state']['fields'] = ['undeclared' => ['search' => ['value' => 'x', 'matchType' => 'contains']]];
    $this->postJson('/dapter/data/KernelPagingRecords', $intent)->assertStatus(422);
    expect(KernelPagingRecords::$reads)->toBe(0)->and(KernelPagingRecords::$scopes)->toBe(0);
});

it('rejects simple lookahead overflow before acquiring source rows', function (): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Simple);
    $this->postJson('/dapter/data/KernelPagingRecords', kernelPagingIntent(['mode' => 'simple', 'current' => 9007199254740991, 'per_page' => 5]))->assertStatus(422);
    expect(KernelPagingRecords::$reads)->toBe(0);
});

it('preserves direct legacy validate and offset-only custom driver capabilities', function (): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Simple);
    $options = (new OptionsResolver)->resolve(new KernelPagingRecords);
    $intent = ReadIntent::fromJson('{"version":2}');
    expect($intent->paging($options))->toBeInstanceOf(SimpleRequest::class);
    expect(fn () => $intent->validate($options))->toThrow(DapterException::class);
    expect((new DriverCapabilities)->supports(PagingMode::Simple))->toBeFalse()->and((new DriverCapabilities)->supports(PagingMode::Cursor))->toBeFalse();
});

it('uses one fresh capability profile per public custom-driver read', function (): void {
    $driver = new class implements StoreDriver
    {
        public int $profiles = 0;

        public function capabilities(): DriverCapabilities
        {
            $this->profiles++;

            return new DriverCapabilities(sourceType: CollectionSource::class);
        }

        public function execute(ValidatedReadPlan $plan): RawPage
        {
            return new RawPage([], $plan->paging, 1, 0);
        }
    };
    resolve(DriverRegistry::class)->register('kernel_custom', static fn (KernelPagingRecords $source): StoreDriver => $driver);
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->driver('kernel_custom');
    $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 2])->assertOk();
    expect($driver->profiles)->toBe(1);
    $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 2])->assertOk();
    expect($driver->profiles)->toBe(2);
});

it('rejects wrong source families immediately through registry make and the public route', function (): void {
    $driver = new class implements StoreDriver
    {
        public int $profiles = 0;

        public int $executions = 0;

        public function capabilities(): DriverCapabilities
        {
            $this->profiles++;

            return new DriverCapabilities(sourceType: EloquentSource::class);
        }

        public function execute(ValidatedReadPlan $plan): RawPage
        {
            $this->executions++;

            return new RawPage([], $plan->paging, 1, 0);
        }
    };
    $registry = resolve(DriverRegistry::class);
    $registry->register('kernel_wrong', static fn (KernelPagingRecords $source): StoreDriver => $driver);
    expect(fn () => $registry->make('kernel_wrong', new KernelPagingRecords))->toThrow(DapterException::class);
    expect($driver->profiles)->toBe(1)->and($driver->executions)->toBe(0);
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->driver('kernel_wrong');
    $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 2])->assertStatus(500);
    expect($driver->profiles)->toBe(2)->and($driver->executions)->toBe(0);
});

it('does not reuse an earlier trusted profile for the same custom driver object', function (): void {
    $driver = new class implements StoreDriver
    {
        public bool $available = true;

        public int $profiles = 0;

        public int $executions = 0;

        public function capabilities(): DriverCapabilities
        {
            $this->profiles++;

            return new DriverCapabilities(offset: $this->available, sourceType: CollectionSource::class);
        }

        public function execute(ValidatedReadPlan $plan): RawPage
        {
            $this->executions++;

            return new RawPage([], $plan->paging, 1, 0);
        }
    };
    resolve(DriverRegistry::class)->register('kernel_mutable', static fn (KernelPagingRecords $source): StoreDriver => $driver);
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->driver('kernel_mutable');
    $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 2])->assertOk();
    $driver->available = false;
    $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 2])->assertStatus(500);
    expect($driver->profiles)->toBe(2)->and($driver->executions)->toBe(1);
});

it('keeps advanced exact counts optional without restoring numbered paging', function (PagingMode $mode): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode($mode)->pageSize(2)->exactCounts()
        ->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)])));
    $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 2])->assertOk()
        ->assertJsonPath('capabilities.paging.counts', 'exact')->assertJsonPath('paging.total', 3)->assertJsonPath('paging.filtered', 3)
        ->assertJsonPath('paging.pages', null)->assertJsonPath('paging.has_next', true);
})->with([PagingMode::Simple, PagingMode::Cursor]);

it('returns only generic invalid-cursor recovery metadata before acquiring rows', function (string $token): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor);
    $this->postJson('/dapter/data/KernelPagingRecords', kernelPagingIntent(['mode' => 'cursor', 'cursor' => $token, 'per_page' => 5]))
        ->assertStatus(422)->assertJsonPath('error.kind', 'validation')->assertJsonPath('error.code', 'invalid_cursor')
        ->assertJsonPath('errors._dapter_kind', ['validation'])->assertJsonPath('errors._dapter_code', ['invalid_cursor']);
    expect(KernelPagingRecords::$reads)->toBe(0);
})->with(['tampered', '', str_repeat('x', 16385)]);

it('requires explicit restart on same-context expiry and reauthorizes before exposing it', function (): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor)->pageSize(2)
        ->query(new QueryOptions(new ResultIdentity([new IdentityColumn('id', 'id', ValueType::Integer)])));
    $token = $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 2])->assertOk()->json('paging.next_cursor');
    $intent = kernelPagingIntent(['mode' => 'cursor', 'cursor' => $token, 'per_page' => 2]);
    $this->travel(900)->seconds();
    KernelPagingRecords::$reads = 0;
    $this->postJson('/dapter/data/KernelPagingRecords', $intent)->assertStatus(409)->assertExactJson([
        'version' => 2,
        'error' => ['kind' => 'cursor_expired', 'code' => 'cursor_expired', 'message' => 'The cursor has expired. Restart pagination to continue.', 'recovery' => 'restart'],
    ]);
    KernelPagingRecords::$settings = KernelPagingRecords::$settings->publicAccess(false);
    KernelPagingRecords::$scopes = 0;
    $this->postJson('/dapter/data/KernelPagingRecords', $intent)->assertUnauthorized();
    expect(KernelPagingRecords::$scopes)->toBe(0);
});

it('requires an application-owned nonempty cursor scope before source acquisition', function (bool $missing): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor);
    $alias = 'KernelPagingRecords';

    if ($missing) {
        resolve(SourceRegistry::class)->register('KernelNoScopeRecords', KernelNoScopeRecords::class);
        $alias = 'KernelNoScopeRecords';
    } else {
        KernelPagingRecords::$scope = '';
    }
    $this->postJson('/dapter/data/'.$alias, ['version' => 2])->assertStatus(500)->assertJsonPath('error.code', 'invalid_source');
    expect(KernelPagingRecords::$reads)->toBe(0);
})->with([false, true]);

it('rejects nonboolean count policy configuration', function (mixed $value): void {
    config()->set('dapter.paging.exact_counts', $value);
    $this->postJson('/dapter/data/KernelPagingRecords', ['version' => 2])->assertStatus(500);
    expect(KernelPagingRecords::$reads)->toBe(0);
})->with([[null], [0], ['true'], [[]]]);
