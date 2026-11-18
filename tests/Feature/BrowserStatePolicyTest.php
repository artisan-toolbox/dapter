<?php

declare(strict_types=1);

use App\DataSources\OptionRecords;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\StoreOptions;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../Fixtures/ListingSources.php';

beforeEach(function (): void {
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    OptionRecords::$reads = 0;
    OptionRecords::$rows = [['id' => 1], ['id' => 2]];
});

it('adds browser persistence policy only to explicitly negotiated successful version two reads', function (): void {
    $baseline = $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertOk()->assertJsonMissingPath('browserState')->json();
    $negotiated = $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()
        ->assertJsonPath('browserState.key', 'option_records')->assertJsonPath('browserState.expiration', ['minutes' => 240]);
    expect($negotiated->json('browserState.version'))->toMatch('/\A[a-f0-9]{64}\z/');
    $body = $negotiated->json();
    unset($body['browserState']);
    expect($body)->toBe($baseline);
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1], ['X-Dapter-Browser-State' => '1'])->assertOk()->assertJsonMissingPath('browserState');
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => 'true'])->assertOk()->assertJsonMissingPath('browserState');
});

it('uses the snake case registered alias and immutable explicit key and minute overrides', function (): void {
    resolve(SourceRegistry::class)->register('Catalog.OrderItems', OptionRecords::class);
    $base = StoreOptions::make()->publicAccess();
    $this->postJson('/dapter/data/Catalog.OrderItems', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->assertJsonPath('browserState.key', 'catalog_order_items');
    OptionRecords::$settings = $base->stateKey('orders:reader')->stateTtl(15);
    $this->postJson('/dapter/data/Catalog.OrderItems', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->assertJsonPath('browserState.key', 'orders:reader')->assertJsonPath('browserState.expiration', ['minutes' => 15]);
    OptionRecords::$settings = $base;
    $this->postJson('/dapter/data/Catalog.OrderItems', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->assertJsonPath('browserState.key', 'catalog_order_items')->assertJsonPath('browserState.expiration', ['minutes' => 240]);
});

it('captures configured minutes at factory creation and excludes configured defaults when requested', function (): void {
    config()->set('dapter.state.ttl', 30);
    $snapshot = StoreOptions::make()->publicAccess();
    config()->set('dapter.state.ttl', 90);
    OptionRecords::$settings = $snapshot;
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->assertJsonPath('browserState.expiration', ['minutes' => 30]);
    OptionRecords::$settings = StoreOptions::makeWithoutDefaults()->pagingMode(PagingMode::Offset)->pageSize(5)->guard('web')->publicAccess();
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->assertJsonPath('browserState.expiration', ['minutes' => 240]);
});

it('freezes an absolute Carbon deadline and emits unix milliseconds without renewing it', function (): void {
    $this->travelTo(Date::parse('2026-11-18T10:00:00.123Z'));
    $deadline = Date::parse('2026-11-18T10:05:00.456Z');
    $expected = $deadline->getTimestampMs();
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->stateTtl($deadline);
    $deadline->addHour();
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->assertJsonPath('browserState.expiration', ['at' => $expected]);
    $this->travel(2)->minutes();
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->assertJsonPath('browserState.expiration', ['at' => $expected]);
});

it('rejects invalid browser state declarations before reading records', function (string $case): void {
    OptionRecords::$settings = match ($case) {
        'zero' => StoreOptions::make()->publicAccess()->stateTtl(0),
        'negative' => StoreOptions::make()->publicAccess()->stateTtl(-1),
        'overflow' => StoreOptions::make()->publicAccess()->stateTtl(PHP_INT_MAX),
        'negative-deadline' => StoreOptions::make()->publicAccess()->stateTtl(Date::createFromTimestamp(-1)),
        'empty-key' => StoreOptions::make()->publicAccess()->stateKey(''),
        'blank-key' => StoreOptions::make()->publicAccess()->stateKey('  '),
        'control-key' => StoreOptions::make()->publicAccess()->stateKey("orders\nreader"),
        default => throw new LogicException('Unknown browser state policy fixture.'),
    };
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertStatus(500)->assertJsonMissingPath('browserState');
    expect(OptionRecords::$reads)->toBe(0);
})->with(['zero', 'negative', 'overflow', 'negative-deadline', 'empty-key', 'blank-key', 'control-key']);

it('rejects malformed state configuration at the options boundary', function (mixed $value): void {
    config()->set('dapter.state', $value);
    expect(fn (): StoreOptions => StoreOptions::make())->toThrow(DapterException::class);
})->with([[null], [[]], [['ttl' => 0]], [['ttl' => '240']], [['ttl' => 1.5]], [['ttl' => true]], [['ttl' => 10, 'unknown' => true]], [['ttl' => PHP_INT_MAX]]]);

it('authorizes negotiated browser state before exposing source metadata or reading rows', function (): void {
    OptionRecords::$settings = StoreOptions::make();
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertUnauthorized()->assertJsonMissingPath('browserState');
    $this->actingAs(new GenericUser(['id' => 7]));
    Gate::define('browser-state-denied', static fn (GenericUser $user): bool => false);
    OptionRecords::$settings = StoreOptions::make()->authorize('browser-state-denied');
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertForbidden()->assertJsonMissingPath('browserState');
    expect(OptionRecords::$reads)->toBe(0);
});

it('keeps the browser version independent from runtime resolved options and configuration', function (): void {
    $version = $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->json('browserState.version');
    config()->set('dapter.state.ttl', 15);
    config()->set('dapter.paging.per_page', 5);
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->stateKey('another-key')->strictLiteralSearch()->stateTtl(now()->addHour());
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->assertJsonPath('browserState.version', $version);
    expect((new OptionsResolver)->resolve(new OptionRecords)->browserState->key)->toBe('another-key');
});

it('discards an obsolete saved declaration before selecting removed fields or decoding its cursor', function (): void {
    $state = ['version' => 2, 'fields' => ['removed' => ['search' => ['value' => 'old', 'matchType' => 'contains']]], 'global' => (object) [], 'searchMatch' => 'all', 'order' => ['kind' => 'default'], 'paging' => ['mode' => 'cursor', 'cursor' => 'obsolete-cursor', 'per_page' => 1]];
    $headers = ['X-Dapter-Browser-State' => '1', 'X-Dapter-State-Version' => str_repeat('0', 64)];
    $response = $this->postJson('/dapter/data/OptionRecords', ['version' => 2, 'state' => $state], $headers)->assertOk()
        ->assertJsonPath('requested.paging.mode', 'offset')->assertJsonCount(0, 'requested.fields')->assertJsonPath('requested.order.kind', 'default');
    expect($response->json('browserState.version'))->not->toBe($headers['X-Dapter-State-Version']);
    expect(OptionRecords::$reads)->toBe(1);
});

it('keeps validation active for current version criteria and does not negotiate with the version header alone', function (): void {
    $version = $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()->json('browserState.version');
    $state = ['version' => 2, 'fields' => ['removed' => ['search' => ['value' => 'old', 'matchType' => 'contains']]], 'global' => (object) [], 'searchMatch' => 'all', 'order' => ['kind' => 'default'], 'paging' => ['mode' => 'offset', 'current' => 1, 'per_page' => 10]];
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2, 'state' => $state], ['X-Dapter-Browser-State' => '1', 'X-Dapter-State-Version' => $version])->assertStatus(422);
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2, 'state' => $state], ['X-Dapter-State-Version' => str_repeat('0', 64)])->assertStatus(422);
});

it('keeps source defaults readable after an absolute browser deadline is reached', function (): void {
    $deadline = now()->addMinute();
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->stateTtl($deadline);
    $this->travel(2)->minutes();
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2], ['X-Dapter-Browser-State' => '1'])->assertOk()
        ->assertJsonPath('browserState.expiration.at', $deadline->getTimestampMs())->assertJsonCount(2, 'data');
});
