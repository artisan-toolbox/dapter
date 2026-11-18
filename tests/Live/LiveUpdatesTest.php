<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Broadcasting\DataStores;
use ArtisanToolbox\Dapter\Broadcasting\LiveDefinition;
use ArtisanToolbox\Dapter\Broadcasting\LiveSourceRegistration;
use ArtisanToolbox\Dapter\Broadcasting\PrivateChannel;
use ArtisanToolbox\Dapter\Broadcasting\RecipientContexts;
use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Drivers\DriverCapabilities;
use ArtisanToolbox\Dapter\Drivers\DriverRegistry;
use ArtisanToolbox\Dapter\Drivers\RawPage;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\StoreOptions;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveAudience;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveContexts;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveGateSubject;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveResource;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveRevision;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveRows;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveState;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveTestCase;
use ArtisanToolbox\Dapter\Tests\Fixtures\PublicRows;
use Illuminate\Auth\Access\Response;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

require_once __DIR__.'/../Fixtures/LiveSources.php';
uses(LiveTestCase::class);

beforeEach(function () {
    LiveState::$principals = [1 => ['tenant' => 'north'], 2 => ['tenant' => 'south']];
    LiveState::$rows = [['id' => 1, 'name' => 'Notebook', 'tenant' => 'north', 'revision' => '9'], ['id' => '1', 'name' => 'Typed notebook', 'tenant' => 'north', 'revision' => '18446744073709551615'], ['id' => 2, 'name' => 'South book', 'tenant' => 'south', 'revision' => '1']];
    LiveState::$events = [];
    LiveState::$reads = 0;
    LiveState::$actionCalls = 0;
    LiveState::$allowed = true;
    LiveState::$gateChecks = [];
    LiveState::$producer = true;
    LiveState::$mismatch = false;
    LiveState::$malformed = false;
    LiveState::$runtime = null;
    $this->actingAs(new GenericUser(['id' => 1, 'tenant' => 'north']), 'live');
});

it('lists projected typed records on v2 and preserves the exact v1 envelope', function () {
    $v2 = $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertOk()
        ->assertJsonPath('metadata.records.mode', 'revisioned')->assertJsonPath('metadata.records.rows.0.id', 1)->assertJsonPath('metadata.records.rows.1.id', '1')
        ->assertJsonPath('data.0.reader', 1)->assertJsonPath('data.0.nested.reader', 1)
        ->assertJsonPath('metadata.channel.type', 'private');
    expect(array_keys($v2->json('metadata')))->toBe(['records', 'channel']);
    expect($v2->json('metadata.channel.name'))->toMatch('/^live\.rows\.[A-Za-z0-9_-]{43}$/');
    $this->postJson('/dapter/data/LiveRows', ['version' => 1])->assertOk()->assertJsonMissingPath('metadata');
});

it('executes a listed record action with its restored private source and fresh actor', function (): void {
    $checked = [];
    $allowed = true;
    Gate::define('inspect-live-record', static function (Authenticatable $user, array $record) use (&$checked, &$allowed): bool {
        $checked[] = [$user->getAuthIdentifier(), $record['name']];

        return $allowed;
    });
    $this->actingAs(new GenericUser(['id' => 99]), 'web');
    $listing = $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertOk();
    $url = $listing->json('actions.inspect.target.values.0.url');
    $channel = 'private-'.$listing->json('metadata.channel.name');
    $this->travel(12)->hours();
    $headers = ['X-Dapter-Action' => '1', 'X-Dapter-Transport' => 'http'];
    $this->postJson($url, ['id' => 1], $headers)->assertOk()->assertExactJson(['actor' => 1, 'tenant' => 'north', 'record' => 'Notebook']);
    $this->travel(12)->hours();
    $this->travel(1)->second();
    $this->postJson('/broadcasting/auth', ['channel_name' => $channel])->assertForbidden();
    $allowed = false;
    $this->postJson($url, ['id' => 1], $headers)->assertForbidden();
    LiveState::$principals[1]['tenant'] = 'south';
    $this->postJson($url, ['id' => 1], $headers)->assertNotFound();
    unset(LiveState::$principals[1]);
    $this->postJson($url, ['id' => 1], $headers)->assertForbidden();
    expect($checked)->toBe([[1, 'Notebook'], [1, 'Notebook'], [1, 'Notebook']])->and(LiveState::$actionCalls)->toBe(1);
});

it('keeps the native action actor independent of public projection and producer authorization', function (): void {
    $checked = [];
    Gate::define('inspect-public-record', static function (?Authenticatable $actor, array $record) use (&$checked): bool {
        $checked[] = $actor?->getAuthIdentifier();

        return $record['name'] === 'Public notebook';
    });
    $this->actingAs(new GenericUser(['id' => 99]), 'web');
    LiveState::$producer = false;
    $url = $this->postJson('/dapter/data/PublicRows', ['version' => 2])->assertOk()
        ->assertJsonPath('data.0', ['name' => 'Public notebook'])->json('actions.inspect.target.values.0.url');
    $this->postJson($url, ['id' => 1], ['X-Dapter-Action' => '1', 'X-Dapter-Transport' => 'http'])->assertOk()
        ->assertExactJson(['actor' => 1, 'record' => 'Public notebook', 'sourcePath' => 'public', 'sourceReader' => null]);
    $this->app['auth']->guard('live')->forgetUser();
    $this->postJson($url, ['id' => 1], ['X-Dapter-Action' => '1', 'X-Dapter-Transport' => 'http'])->assertOk()
        ->assertExactJson(['actor' => null, 'record' => 'Public notebook', 'sourcePath' => 'public', 'sourceReader' => null]);
    expect($checked)->toBe([null, 1, null])->and(LiveState::$actionCalls)->toBe(2);
});

it('honors the native source Gate status for a public action under its selected actor', function (): void {
    Gate::define('inspect-public-record', static fn (?Authenticatable $actor): bool => true);
    $url = $this->postJson('/dapter/data/PublicRows', ['version' => 2])->assertOk()->json('actions.inspect.target.values.0.url');
    $actors = [];
    Gate::define('public-action-access', static function (?Authenticatable $actor) use (&$actors): Response {
        $actors[] = $actor?->getAuthIdentifier();

        return Response::denyAsNotFound();
    });
    LiveState::$runtime = PublicRows::declaration()->authorize('public-action-access');
    $this->postJson($url, ['id' => 1], ['X-Dapter-Action' => '1', 'X-Dapter-Transport' => 'http'])->assertNotFound();
    expect($actors)->toBe([1])->and(LiveState::$actionCalls)->toBe(0);
});

it('requires the exact live registration for reads and native actions on a broadcasting source', function (): void {
    $conventionalClass = app()->getNamespace().'DataSources\\ConventionalPublicRows';

    if (! class_exists($conventionalClass)) {
        class_alias(PublicRows::class, $conventionalClass);
    }
    Gate::define('inspect-public-record', static fn (?Authenticatable $actor): bool => true);
    $headers = ['X-Dapter-Action' => '1', 'X-Dapter-Transport' => 'http'];
    $this->postJson('/dapter/data/ConventionalPublicRows', ['version' => 2])->assertStatus(500)->assertJsonPath('error.code', 'invalid_source');
    $this->postJson('/dapter/actions/ConventionalPublicRows/inspect/1', ['id' => 1], $headers)->assertStatus(500);
    expect(LiveState::$reads)->toBe(0)->and(LiveState::$actionCalls)->toBe(0);
    $this->postJson('/dapter/actions/PublicRows/inspect/1', ['id' => 1], $headers)->assertOk()->assertJsonPath('record', 'Public notebook');
});

it('applies the selected guard and source Gate before reporting a missing live registration', function (bool $required, int $status): void {
    resolve(SourceRegistry::class)->register('UnregisteredRows', PublicRows::class);
    $this->app['auth']->guard('live')->forgetUser();
    $this->actingAs(new GenericUser(['id' => 99]), 'web');
    Gate::define('hide-unregistered', static fn (?Authenticatable $actor): Response => Response::denyAsNotFound());
    LiveState::$runtime = PublicRows::declaration()->publicAccess(! $required)->authorize('hide-unregistered');

    $this->postJson('/dapter/data/UnregisteredRows', ['version' => 2])->assertStatus($status);
    $this->postJson('/dapter/actions/UnregisteredRows/inspect/1', ['id' => 1], ['X-Dapter-Action' => '1', 'X-Dapter-Transport' => 'http'])->assertStatus($status);
    expect(LiveState::$reads)->toBe(0)->and(LiveState::$actionCalls)->toBe(0);
})->with(['selected guard requires a user' => [true, 401], 'source Gate conceals the source' => [false, 404]]);

it('authorizes the exact selected guard and refreshes current membership for native channel admission', function () {
    $descriptor = $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertOk()->json('metadata.channel');
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-'.$descriptor['name']])->assertOk();
    LiveState::$principals[1]['tenant'] = 'south';
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-'.$descriptor['name']])->assertForbidden();
    expect(LiveState::$reads)->toBe(1);
});

it('denies revoked principals and mismatched explicit audience before source reads', function () {
    unset(LiveState::$principals[1]);
    $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertForbidden();
    LiveState::$principals[1] = ['tenant' => 'north'];
    LiveState::$mismatch = true;
    $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertForbidden();
    expect(LiveState::$reads)->toBe(0);
});

it('rejects late live declarations through the sealed public registry', function () {
    expect(fn () => resolve(SourceRegistry::class)->registerLive('LateRows', new LiveSourceRegistration(LiveRows::class, LiveRows::declaration(), LiveContexts::class, 'late-v1')))->toThrow(DapterException::class);
});

it('publishes only requested typed rows through the native immediate broadcaster after commit', function () {
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    DB::beginTransaction();
    $store->update([1, '1', 1, 999, 2]);
    expect(LiveState::$reads)->toBe(0)->and(LiveState::$events)->toBe([]);
    DB::commit();
    expect(LiveState::$events)->toHaveCount(2)->and(LiveState::$reads)->toBe(1);
    $first = LiveState::$events[0];
    expect($first['event'])->toBe('dapter.updated')->and($first['payload']['records']['rows'][0]['id'])->toBe(1)
        ->and($first['payload']['data'][0]->reader)->toBe(1)->and(array_keys($first['payload']))->toBe(['version', 'eventId', 'source', 'channel', 'data', 'records']);
});

it('drops nested rollback updates and waits for outer commit after nested success', function () {
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    DB::beginTransaction();
    DB::beginTransaction();
    $store->update(1);
    DB::rollBack();
    DB::beginTransaction();
    $store->update('1');
    DB::commit();
    expect(LiveState::$events)->toBe([]);
    DB::commit();
    expect(LiveState::$events)->toHaveCount(1)->and(LiveState::$events[0]['payload']['records']['rows'][0]['id'])->toBe('1');
});

it('denies execution after revocation and validates the whole command before any publication', function () {
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    DB::beginTransaction();
    $store->update(1);
    LiveState::$allowed = false;
    DB::commit();
    expect(LiveState::$reads)->toBe(0)->and(LiveState::$events)->toBe([]);
    LiveState::$allowed = true;
    LiveState::$rows[1]['revision'] = '01';
    expect(fn () => $store->update([1, '1']))->toThrow(DapterException::class);
    expect(LiveState::$events)->toBe([]);
});

it('makes empty updates a complete no-op and rejects malformed bounded batches atomically', function () {
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    $store->update([]);
    foreach ([null, true, 1.0, '', [2 => 1], array_fill(0, 101, 1), [1, null], str_repeat('a', 1025), "\xff", 9007199254740992] as $invalid) {
        expect(fn () => (new ReflectionMethod($store, 'update'))->invoke($store, $invalid))->toThrow(DapterException::class);
    }
    expect(LiveState::$reads)->toBe(0)->and(LiveState::$events)->toBe([]);
});

it('uses an anonymous public projection and denies guest or revoked producers', function () {
    $this->app['auth']->forgetGuards();
    $this->postJson('/dapter/data/PublicRows', ['version' => 2])->assertOk()->assertJsonPath('data.0', ['name' => 'Public notebook'])->assertJsonPath('metadata.channel.name', 'live.public');
    $stores = resolve(DataStores::class);
    expect(fn () => $stores->recipient('PublicRows', request()))->toThrow(DapterException::class);
    $this->actingAs(new GenericUser(['id' => 1, 'tenant' => 'north']), 'live');
    $store = $stores->forRecipient($stores->recipient('PublicRows', request()), 'testing');
    $store->update(1);
    expect(LiveState::$events)->toHaveCount(1);
    LiveState::$producer = false;
    $store->update(1);
    expect(LiveState::$events)->toHaveCount(1);
});

it('targets the named mutation transaction while another connection is pending', function () {
    config()->set('database.connections.other-live', ['driver' => 'sqlite', 'database' => ':memory:']);
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    DB::connection('testing')->beginTransaction();
    DB::connection('other-live')->beginTransaction();
    $store->update(1);
    DB::connection('other-live')->commit();
    expect(LiveState::$events)->toBe([]);
    DB::connection('testing')->rollBack();
    expect(LiveState::$events)->toBe([]);
    DB::connection('testing')->beginTransaction();
    DB::connection('other-live')->beginTransaction();
    $store->update(1);
    DB::connection('testing')->commit();
    expect(LiveState::$events)->toHaveCount(1);
    DB::connection('other-live')->rollBack();
});

it('serializes encrypted queue work and restores a fresh recipient through the actual Laravel Worker', function () {
    DB::connection('testing')->getSchemaBuilder()->create('dapter_live_jobs', static function (Blueprint $table): void {
        $table->id();
        $table->string('queue');
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    config()->set('queue.connections.live-proof', ['driver' => 'database', 'connection' => 'testing', 'table' => 'dapter_live_jobs', 'queue' => 'live-proof', 'retry_after' => 90]);
    config()->set('dapter.broadcasting.queue_connection', 'live-proof');
    config()->set('dapter.broadcasting.queue', 'live-proof');
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    $store->update(1);
    $payload = DB::table('dapter_live_jobs')->value('payload');
    expect($payload)->toBeString()->not->toContain('Notebook', 'north', 'recipient', 'revision', 'name')
        ->and(LiveState::$events)->toBe([])->and(LiveState::$reads)->toBe(0);
    $ambient = Request::create('/worker-ambient');
    $other = new GenericUser(['id' => 2, 'tenant' => 'south']);
    app()->instance('request', $ambient);
    $ambient->setUserResolver(static fn () => $other);
    $originalResolver = $ambient->getUserResolver();
    LiveState::$rows[0]['name'] = 'Committed notebook';
    $failures = [];
    resolve('events')->listen(JobExceptionOccurred::class, static function ($event) use (&$failures): void {
        $failures[] = $event->exception;
    });
    resolve('queue.worker')->runNextJob('live-proof', 'live-proof', new WorkerOptions(sleep: 0));
    expect($failures)->toBe([])->and(DB::table('dapter_live_jobs')->count())->toBe(0)
        ->and(LiveState::$events)->toHaveCount(1)->and(LiveState::$events[0]['payload']['data'][0]->name)->toBe('Committed notebook')
        ->and(LiveState::$events[0]['payload']['data'][0]->nested->reader)->toBe(1)
        ->and(resolve('request'))->toBe($ambient)->and($ambient->getUserResolver())->toBe($originalResolver)->and($ambient->user())->toBe($other);
    resolve('queue')->connection('live-proof')->pushRaw($payload, 'live-proof');
    resolve('queue.worker')->runNextJob('live-proof', 'live-proof', new WorkerOptions(sleep: 0));
    expect(LiveState::$events)->toHaveCount(2)->and(LiveState::$events[0]['payload']['eventId'])->toBe(LiveState::$events[1]['payload']['eventId']);
    resolve('queue')->connection('live-proof')->pushRaw($payload, 'live-proof');
    LiveState::$principals[1]['tenant'] = 'south';
    $reads = LiveState::$reads;
    resolve('queue.worker')->runNextJob('live-proof', 'live-proof', new WorkerOptions(sleep: 0));
    expect($failures)->toBe([])->and(LiveState::$reads)->toBe($reads)->and(LiveState::$events)->toHaveCount(2);
});

it('keeps default and configured queue selection explicit and validates names before dispatch', function () {
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    config()->set('dapter.broadcasting.queue_connection', 'missing-live-connection');
    expect(fn () => $store->update(1))->toThrow(DapterException::class);
    config()->set('dapter.broadcasting.queue_connection', null);
    config()->set('dapter.broadcasting.queue', '');
    expect(fn () => $store->update(1))->toThrow(DapterException::class);
    expect(LiveState::$reads)->toBe(0)->and(LiveState::$events)->toBe([]);
});

it('rejects intersecting private patterns and public names atomically without constructing sources', function () {
    $registry = new SourceRegistry;
    $first = LiveRows::declaration();
    $registry->registerLive('First', new LiveSourceRegistration(LiveRows::class, $first, LiveContexts::class, 'rows-v1'));
    expect(fn () => $registry->registerLive('Second', new LiveSourceRegistration(LiveRows::class, $first, LiveContexts::class, 'rows-v1')))->toThrow(DapterException::class);
    expect($registry->className('Second'))->toBeNull()->and(LiveState::$reads)->toBe(0);
    expect(LiveDefinition::overlaps('orders.{context}.north', 'orders.south.{context}'))->toBeTrue();
    foreach (['orders.{context}.tail.{context}', 'orders.pre{context}', 'private-orders.{context}', str_repeat('a', 65).'.{context}'] as $pattern) {
        $options = StoreOptions::make()->guard('live')->broadcastUpdatable(channel: new PrivateChannel($pattern, LiveAudience::class));
        expect(fn () => (new SourceRegistry)->registerLive('Invalid', new LiveSourceRegistration(LiveRows::class, $options, LiveContexts::class, 'rows-v1')))->toThrow(DapterException::class);
    }
});

it('intersects registered and runtime Gates and forbids runtime channel retargeting before acquisition', function () {
    Gate::define('runtime-denied', static fn () => false);
    LiveState::$runtime = StoreOptions::make()->guard('live')->authorize('runtime-denied')->useResource(LiveResource::class)->broadcastUpdatable(channel: new PrivateChannel('live.rows.{context}', LiveAudience::class), revisions: LiveRevision::class);
    $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertForbidden();
    LiveState::$runtime = StoreOptions::make()->guard('live')->useResource(LiveResource::class)->broadcastUpdatable(channel: new PrivateChannel('live.retargeted.{context}', LiveAudience::class), revisions: LiveRevision::class);
    $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertStatus(500);
    expect(LiveState::$reads)->toBe(0);
});

it('uses exactly one configured guard even when another guard is simultaneously authenticated', function () {
    $this->actingAs(new GenericUser(['id' => 99]), 'web');
    $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertOk()->assertJsonPath('data.0.reader', 1);
    $descriptor = $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertOk()->json('metadata.channel');
    $this->app['auth']->guard('live')->forgetUser();
    $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertUnauthorized();
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-'.$descriptor['name']])->assertForbidden();
});

it('expires cached admission and recipient references without publishing and permits a fresh listing', function () {
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    $descriptor = $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertOk()->json('metadata.channel');
    $this->travel(86401)->seconds();
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-'.$descriptor['name']])->assertForbidden();
    $before = LiveState::$reads;
    $store->update(1);
    expect(LiveState::$reads)->toBe($before)->and(LiveState::$events)->toBe([]);
    $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertOk();
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-'.$descriptor['name']])->assertOk();
});

it('keeps a refreshed private channel admission valid after processing an older recipient', function (): void {
    $stores = resolve(DataStores::class);
    $old = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    $this->travel(12)->hours();
    $listing = $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertOk();
    $channel = ['channel_name' => 'private-'.$listing->json('metadata.channel.name')];
    $old->update(1);
    expect(LiveState::$events)->toHaveCount(1);
    $this->travel(13)->hours();
    $this->postJson('/broadcasting/auth', $channel)->assertOk();
    $old->update(1);
    expect(LiveState::$events)->toHaveCount(1);
    $this->travel(11)->hours();
    $this->postJson('/broadcasting/auth', $channel)->assertForbidden();
});

it('preserves full server-side principal identifiers without exposing them in channel names', function () {
    $identifier = PHP_INT_MAX;
    LiveState::$principals[$identifier] = ['tenant' => 'north'];
    $this->actingAs(new GenericUser(['id' => $identifier]), 'live');
    $descriptor = resolve(RecipientContexts::class)->listing('LiveRows', request())->channel;
    expect($descriptor['name'])->not->toContain((string) $identifier, 'north');
    $reference = resolve(DataStores::class)->recipient('LiveRows', request());
    expect($reference->encrypted)->not->toContain((string) $identifier, 'north');
});

it('keeps revisioned metadata aligned on empty pages and accepts the maximum update string length', function (): void {
    LiveState::$rows = [];
    $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertOk()->assertJsonPath('data', [])
        ->assertJsonPath('metadata.records', ['mode' => 'revisioned', 'format' => 'uint64-decimal', 'rows' => []]);
    $identifier = str_repeat('a', 1024);
    LiveState::$rows = [['id' => $identifier, 'name' => 'Long identifier', 'tenant' => 'north', 'revision' => '0']];
    $stores = resolve(DataStores::class);
    $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing')->update($identifier);
    expect(LiveState::$events)->toHaveCount(1)->and(LiveState::$events[0]['payload']['records']['rows'][0]['id'])->toBe($identifier);
});

it('rejects deferred public projection values and oversized commands without partial publication', function (): void {
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('PublicRows', request()), 'testing');
    LiveState::$malformed = true;
    expect(fn () => $store->update(1))->toThrow(DapterException::class);
    expect(LiveState::$events)->toBe([]);
    LiveState::$malformed = false;
    $private = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    LiveState::$rows[1]['name'] = str_repeat('a', 65536);
    expect(fn () => $private->update([1, '1']))->toThrow(DapterException::class);
    expect(LiveState::$events)->toBe([]);
});

it('evaluates registered and fresh effective object Gate operands without serializing them', function (): void {
    $subject = new LiveGateSubject(['id' => 8, 'allowed' => true]);
    LiveState::$runtime = LiveRows::declaration()->authorize('read-live', [$subject]);
    $stores = resolve(DataStores::class);
    $store = $stores->forRecipient($stores->recipient('LiveRows', request()), 'testing');
    expect(LiveState::$gateChecks)->toBe([7, 8]);
    $store->update(1);
    expect(LiveState::$gateChecks)->toBe([7, 8, 7, 8])->and(LiveState::$events)->toHaveCount(1);
    $subject->setAttribute('allowed', false);
    $before = LiveState::$reads;
    $store->update(1);
    expect(LiveState::$gateChecks)->toBe([7, 8, 7, 8, 7, 8])->and(LiveState::$reads)->toBe($before)->and(LiveState::$events)->toHaveCount(1);
    $subject->setAttribute('allowed', true);
    LiveState::$allowed = false;
    $store->update(1);
    expect(LiveState::$gateChecks)->toBe([7, 8, 7, 8, 7, 8, 7])->and(LiveState::$reads)->toBe($before)->and(LiveState::$events)->toHaveCount(1);
});

it('keeps custom drivers compatible while rejecting missing optional live lookup capability before execution', function (): void {
    $executions = 0;
    resolve(DriverRegistry::class)->register('legacylive', static function (LiveRows $source) use (&$executions): StoreDriver {
        return new class($executions) implements StoreDriver
        {
            public function __construct(private int &$executions) {}

            public function capabilities(): DriverCapabilities
            {
                return new DriverCapabilities;
            }

            public function execute(ValidatedReadPlan $plan): RawPage
            {
                $this->executions++;

                throw new LogicException('An ineligible live driver must not execute.');
            }
        };
    });
    LiveState::$runtime = LiveRows::declaration()->driver('legacylive');
    $this->postJson('/dapter/data/LiveRows', ['version' => 2])->assertStatus(500);
    expect($executions)->toBe(0)->and(LiveState::$reads)->toBe(0);
});

it('invalidates retained recipient context when source literal policy changes', function (): void {
    $stores = resolve(DataStores::class);
    $recipient = $stores->recipient('LiveRows', request());
    $store = $stores->forRecipient($recipient, 'testing');
    LiveState::$runtime = LiveRows::declaration()->strictLiteralSearch();
    expect(fn () => $store->update(1))->toThrow(DapterException::class);
    expect(LiveState::$reads)->toBe(0)->and(LiveState::$events)->toBe([]);
});

it('includes resolved literal policy in live declaration fingerprints', function (): void {
    $registration = new LiveSourceRegistration(LiveRows::class, LiveRows::declaration(), LiveContexts::class, 'literal-policy-v1');
    $first = new LiveDefinition('LiveRows', $registration);
    $second = new LiveDefinition('LiveRows', new LiveSourceRegistration(LiveRows::class, LiveRows::declaration()->strictLiteralSearch(), LiveContexts::class, 'literal-policy-v1'));
    expect($first->fingerprint)->not->toBe($second->fingerprint);
});
