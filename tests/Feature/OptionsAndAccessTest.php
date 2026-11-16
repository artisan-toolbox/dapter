<?php

declare(strict_types=1);

use App\DataSources\OptionRecords;
use App\DataSources\UnrelatedSourceHelper;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../Fixtures/ListingSources.php';

it('rejects unrelated conventional classes before constructing them', function () {
    UnrelatedSourceHelper::$constructions = 0;

    $this->postJson('/dapter/data/UnrelatedSourceHelper', ['version' => 1])
        ->assertStatus(500)->assertJsonPath('error.kind', 'server');

    expect(UnrelatedSourceHelper::$constructions)->toBe(0);
});

beforeEach(function () {
    OptionRecords::$settings = null;
    OptionRecords::$reads = 0;
    OptionRecords::$hooks = 0;
    OptionRecords::$rows = array_map(static fn (int $id): array => ['id' => $id], range(1, 27));
});

it('uses normal defaults without an options hook or attributes', function () {
    $this->postJson('/dapter/data/RequiredRecords', ['version' => 1])->assertUnauthorized()->assertJsonPath('error.kind', 'access');
    expect(OptionRecords::$reads)->toBe(0);
    $this->actingAs(new GenericUser(['id' => 7]));
    $this->postJson('/dapter/data/RequiredRecords', ['version' => 1])->assertOk()->assertJsonPath('data.0.reader', 7);
    $this->postJson('/dapter/data/RawRecords', ['version' => 1])->assertOk()->assertJsonPath('data.0.name', 'Raw contract');
});

it('merges inherited attributes and explicit setters without erasing uncalled values', function () {
    OptionRecords::$settings = StoreOptions::make()->pageSize(10);
    $this->postJson('/dapter/data/ChildAttributeRecords', ['version' => 1])
        ->assertOk()->assertJsonCount(10, 'data')
        ->assertJsonPath('capabilities.paging.per_page_options', [5, 25, 10]);
    expect(OptionRecords::$hooks)->toBe(1);
    OptionRecords::$settings = StoreOptions::make();
    $this->postJson('/dapter/data/ChildAttributeRecords', ['version' => 1])
        ->assertOk()->assertJsonCount(25, 'data')->assertJsonPath('capabilities.paging.per_page', 25);
});

it('retains only the final chosen size independently of setter order', function (bool $reverse) {
    $options = StoreOptions::make()->publicAccess();
    OptionRecords::$settings = $reverse ? $options->pageSizeOptions([5, 50, 5])->pageSize(25) : $options->pageSize(15)->pageSize(25)->pageSizeOptions([5, 50, 5]);
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()->assertJsonPath('capabilities.paging.per_page_options', [5, 50, 25]);
})->with([false, true]);

it('keeps fluent declarations immutable and isolated', function () {
    $base = StoreOptions::make()->publicAccess();
    OptionRecords::$settings = $base->pageSize(5);
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()->assertJsonCount(5, 'data');
    OptionRecords::$settings = $base;
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()->assertJsonCount(10, 'data');
});

it('supports a complete empty baseline and inherited attributes', function () {
    OptionRecords::$settings = StoreOptions::makeWithoutDefaults()->publicAccess()->guard('web')->pagingMode(PagingMode::Offset)->pageSize(3);
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()->assertJsonPath('capabilities.paging.per_page_options', [3]);
    OptionRecords::$settings = StoreOptions::makeWithoutDefaults();
    $this->postJson('/dapter/data/AttributeRecords', ['version' => 1])->assertOk()->assertJsonPath('capabilities.paging.per_page_options', [5, 25]);
});

it('rejects incomplete or invalid source declarations before reads', function (string $case) {
    OptionRecords::$settings = match ($case) {
        'empty' => StoreOptions::makeWithoutDefaults()->publicAccess(),
        'size' => StoreOptions::make()->publicAccess()->pageSize(0),
        'sizes' => StoreOptions::make()->publicAccess()->pageSizeOptions([0]),
        'guard' => StoreOptions::make()->publicAccess()->guard('missing'),
        'ability' => StoreOptions::make()->publicAccess()->authorize(''),
        default => null,
    };
    $source = match ($case) {
        'duplicate' => 'DuplicateAttributes', 'undeclared' => 'UndeclaredOptions', default => 'OptionRecords',
    };
    $this->postJson('/dapter/data/'.$source, ['version' => 1])->assertStatus(500)->assertJsonPath('error.kind', 'server');
    expect(OptionRecords::$reads)->toBe(0);
})->with(['empty', 'size', 'sizes', 'guard', 'ability', 'duplicate', 'undeclared']);

it('isolates the selected guard and leaves the global guard unchanged', function () {
    config()->set('auth.guards.alternate', config('auth.guards.web'));
    $this->actingAs(new GenericUser(['id' => 1]), 'web');
    $this->actingAs(new GenericUser(['id' => 2]), 'alternate');
    auth()->shouldUse('web');
    OptionRecords::$settings = StoreOptions::make()->guard('alternate')->authorize('selected-reader');
    Gate::define('selected-reader', static fn (GenericUser $user): bool => $user->getAuthIdentifier() === 2);
    OptionRecords::$rows = [['ok' => true]];
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk();
    expect(auth()->getDefaultDriver())->toBe('web');
    auth()->guard('alternate')->forgetUser();
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertUnauthorized();
});

it('checks public guests through Gate and repeats the access check', function () {
    config()->set('dapter_test.allow', true);
    Gate::define('read-records', static function (?GenericUser $user, string $argument): bool {
        return config('dapter_test.allow') === true && $user === null && $argument === 'fixture';
    });
    $this->postJson('/dapter/data/AuthorizedAttributeRecords', ['version' => 1])->assertOk();
    config()->set('dapter_test.allow', false);
    $this->postJson('/dapter/data/AuthorizedAttributeRecords', ['version' => 1])->assertForbidden()->assertJsonPath('error.kind', 'access');
    expect(OptionRecords::$reads)->toBe(1);
});

it('preserves valid Gate denial statuses and uses one access body for hidden sources', function (int $status, int $expected) {
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->authorize('deny-records');
    Gate::define('deny-records', static fn (?GenericUser $user): Response => Response::denyWithStatus($status));
    $denied = $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertStatus($expected)->assertJsonPath('error.kind', 'access')->assertJsonMissingPath('capabilities');

    if ($expected === 422) {
        $denied->assertJsonPath('errors._dapter_kind', ['access']);
    }

    if ($expected === 404) {
        expect($denied->json())->toBe($this->postJson('/dapter/data/MissingRecords', ['version' => 1])->assertNotFound()->json());
    }
    expect(OptionRecords::$reads)->toBe(0);
})->with([[404, 404], [422, 422], [503, 503], [200, 403], [700, 403]]);

it('can restore required login after a public declaration', function () {
    OptionRecords::$settings = StoreOptions::make()->publicAccess(false);
    $this->postJson('/dapter/data/AttributeRecords', ['version' => 1])->assertUnauthorized();
    expect(OptionRecords::$reads)->toBe(0);
});

it('captures configuration at factory creation without putting defaults in the explicit patch', function () {
    $snapshot = StoreOptions::make()->publicAccess();
    config()->set('dapter.paging.per_page', 5);
    config()->set('dapter.paging.per_page_options', [5]);
    OptionRecords::$settings = $snapshot;
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()->assertJsonPath('capabilities.paging.per_page', 10);
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()->assertJsonPath('capabilities.paging.per_page', 5);
});

it('does not treat private source helpers as an undeclared public options hook', function () {
    $this->postJson('/dapter/data/PrivateOptions', ['version' => 1])->assertOk()
        ->assertJsonPath('data.0.name', 'No public options hook')->assertJsonPath('capabilities.paging.per_page', 10);
});

it('gives basic paging and access attributes the same behavior as their fluent equivalents', function () {
    OptionRecords::$settings = StoreOptions::make();
    $attributes = $this->postJson('/dapter/data/AttributeRecords', ['version' => 1])->assertOk()->json();
    OptionRecords::$settings = StoreOptions::make()
        ->pagingMode(PagingMode::Offset)->pageSize(5)->pageSizeOptions([5, 25, 5])->guard('web')->publicAccess();
    expect($this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()->json())->toBe($attributes);
});

it('gives the authorization attribute the same behavior as its fluent equivalent', function () {
    Gate::define('read-records', static fn (?GenericUser $user, string $argument): bool => $user === null && $argument === 'fixture');
    OptionRecords::$rows = [];
    $attributes = $this->postJson('/dapter/data/AuthorizedAttributeRecords', ['version' => 1])->assertOk()->json();
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->authorize('read-records', ['fixture']);
    expect($this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()->json())->toBe($attributes);
});

it('requires each foundational setting when config defaults are excluded', function (string $missing) {
    $options = StoreOptions::makeWithoutDefaults()->publicAccess();

    if ($missing !== 'guard') {
        $options = $options->guard('web');
    }

    if ($missing !== 'mode') {
        $options = $options->pagingMode(PagingMode::Offset);
    }

    if ($missing !== 'size') {
        $options = $options->pageSize(5);
    }
    OptionRecords::$settings = $options;
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertStatus(500)->assertJsonPath('error.kind', 'server');
    expect(OptionRecords::$reads)->toBe(0)->and(OptionRecords::$hooks)->toBe(1);
})->with(['guard', 'mode', 'size']);
