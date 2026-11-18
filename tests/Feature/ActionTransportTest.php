<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Actions\ActionRegistry;
use ArtisanToolbox\Dapter\Attributes\Action;
use ArtisanToolbox\Dapter\Attributes\ActionAuthorize;
use ArtisanToolbox\Dapter\Attributes\PublicAccess;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Enums\ActionTransport;
use ArtisanToolbox\Dapter\Http\ActionInput;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\StoreOptions;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use ArtisanToolbox\Dapter\Tests\Fixtures\ActionParentSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\ActionState;
use ArtisanToolbox\Dapter\Tests\Fixtures\ActionTestSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\ActionUuidRecord;
use ArtisanToolbox\Dapter\Tests\Fixtures\BoundedUuidActionSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\DisjunctiveUuidActionSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\DualActionSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\DuplicateActionSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\GuardActionSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\InvalidRulesOnlyActionSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\ProjectedUuidActionSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\StringActionSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\UuidActionSource;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Uri\WhatWg\Url;

require_once __DIR__.'/../Fixtures/ActionSources.php';

beforeEach(function (): void {
    ActionState::$owners = 0;
    ActionState::$calls = 0;
    ActionState::$forms = 0;
    ActionState::$authorize = true;
    StringActionSource::$key = 'a/b?#%ação';
    resolve(SourceRegistry::class)->register('ActionFixture', ActionTestSource::class);
    foreach (['save', 'parent-save', 'external'] as $ability) {
        Gate::define($ability, static fn (mixed $user = null, mixed $record = null): bool => true);
    }
});

/** @return array<string,string> */
function actionHeaders(string $transport = 'http'): array
{
    return ['X-Dapter-Action' => '1', 'X-Dapter-Transport' => $transport, ...($transport === 'visit' ? ['X-Inertia' => 'true'] : [])];
}

it('discovers ancestor private and direct trait actions without constructing the dedicated owner', function (): void {
    $source = new ActionTestSource;
    $options = (new OptionsResolver)->resolve($source);
    expect(array_keys((new ActionRegistry)->discover($source, $options)))->toEqualCanonicalizing(['save', 'mutateThenFail', 'formOnly', 'inherited', 'traitSave']);
    expect(ActionState::$owners)->toBe(0);
});

it('invokes native HTTP once and returns the handler JSON without a read envelope', function (): void {
    $this->postJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'save', 'target' => 1]), ['id' => 1, 'name' => 'Grace'], actionHeaders())->assertOk()->assertExactJson(['saved' => 1, 'name' => 'Grace']);
    expect(ActionState::$calls)->toBe(1)->and(ActionState::$forms)->toBe(1)->and(ActionState::$owners)->toBe(0);
});

it('rejects target mismatch and exact JSON type mismatches before a FormRequest or mutation', function (mixed $id): void {
    $this->postJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'save', 'target' => 1]), ['id' => $id, 'name' => 'Grace'], actionHeaders())->assertStatus(422);
    expect(ActionState::$calls)->toBe(0)->and(ActionState::$forms)->toBe(0)->and(ActionState::$owners)->toBe(0);
})->with([2, '1', null, true]);

it('rejects unsupported and forged native transports before dependencies', function (): void {
    $this->postJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'traitSave']), ['name' => 'Grace'], actionHeaders('visit'))->assertStatus(405);
    $this->patchJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'traitSave']), ['name' => 'Grace'], actionHeaders('visit'))->assertStatus(406)->assertJsonPath('code', 'unsupported_action_transport');
    $this->patchJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'traitSave']), ['name' => 'Grace'], ['X-Dapter-Action' => '1', 'X-Dapter-Transport' => 'visit'])->assertStatus(406);
    expect(ActionState::$calls)->toBe(0)->and(ActionState::$owners)->toBe(0)->and(ActionState::$forms)->toBe(0);
});

it('authorizes and validates FormRequests before building an external action owner', function (): void {
    ActionState::$authorize = false;
    $this->patchJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'traitSave']), ['name' => 'Grace'], actionHeaders())->assertForbidden();
    ActionState::$authorize = true;
    $this->patchJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'traitSave']), ['name' => ''], actionHeaders())->assertUnprocessable()->assertJsonValidationErrors('name');
    expect(ActionState::$owners)->toBe(0)->and(ActionState::$calls)->toBe(0);
    $this->patchJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'traitSave']), ['name' => 'Grace'], actionHeaders())->assertOk()->assertExactJson(['external' => true]);
    $this->patchJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'traitSave']), ['name' => 'Grace'], actionHeaders())->assertOk();
    expect(ActionState::$owners)->toBe(2)->and(ActionState::$calls)->toBe(2);
});

it('reauthorizes revoked actions before FormRequests and preserves native validation after a mutation', function (): void {
    Gate::define('save', static fn (mixed $user = null): bool => false);
    $this->postJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'save', 'target' => 1]), ['id' => 1, 'name' => 'Grace'], actionHeaders())->assertForbidden();
    expect(ActionState::$calls)->toBe(0)->and(ActionState::$forms)->toBe(0);
    Gate::define('save', static fn (mixed $user = null): bool => true);
    $this->postJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'mutateThenFail']), [], actionHeaders())->assertUnprocessable()->assertJsonValidationErrors('name')->assertJsonMissingPath('version');
    expect(ActionState::$calls)->toBe(1);
});

it('keeps visit redirects and native error bags and permits explicitly authorized FormRequest only calls', function (): void {
    $this->post(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'save', 'target' => 1]), ['id' => 1, 'name' => 'Grace'], actionHeaders('visit'))->assertRedirect('/saved');
    $this->from('/form')->post(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'save', 'target' => 1]), ['id' => 1, 'name' => ''], actionHeaders('visit'))->assertRedirect('/form')->assertSessionHasErrors('name');
    $this->postJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'formOnly']), ['name' => 'Grace'], actionHeaders())->assertOk();
    expect(ActionState::$calls)->toBe(2);
});

it('publishes only independently permitted per page action endpoints on v1 and v2', function (): void {
    Gate::define('save', static fn (mixed $user = null, mixed $record = null): bool => $record === null || $record['id'] === 1);
    foreach ([1, 2] as $version) {
        $body = ['version' => $version];
        $this->postJson(route('dapter.data', ['source' => 'ActionFixture']), $body)->assertOk()->assertJsonPath('actions.save.method', 'post')->assertJsonPath('actions.save.target.values.0.value', 1)->assertJsonMissingPath('actions.formOnly')->assertJsonMissingPath('actions.excluded')->assertJsonMissingPath('actions.save.policy');
    }
});

it('roundtrips string selectors through the listed URL and reads the mandatory source once', function (string $key): void {
    $class = StringActionSource::class;
    resolve(SourceRegistry::class)->register('StringFixture', $class);
    $class::$sources = 0;
    $class::$key = $key;
    Gate::define('string-save', static fn (mixed $user = null, mixed $record = null, mixed $context = null): bool => $record['id'] === $class::$key && $context === 'trusted');
    $metadata = $this->postJson(route('dapter.data', ['source' => 'StringFixture']), ['version' => 2])->assertOk()->json('actions.save');
    expect($class::$sources)->toBe(1)->and($metadata['target']['input'])->toBe('record_id')->and($metadata['target']['values'][0]['value'])->toBe($class::$key);
    $url = (new Url($metadata['target']['values'][0]['url']))->toAsciiString();
    $this->postJson($url, ['record_id' => $key.'x'], actionHeaders())->assertUnprocessable();
    $response = $this->postJson($url, ['record_id' => $key], actionHeaders());
    $response->assertOk()->assertJsonPath('saved', $class::$key)->assertJsonPath('fields.record_id', $key);
    expect($class::$sources)->toBe(2)->and(ActionState::$calls)->toBe(1);
})->with([
    'plain' => 'ABC',
    'reserved and Unicode' => 'a/b?#%ação',
    'balanced braces' => '{draft}',
    'trailing slash' => 'part/',
    'leading slash' => '/part',
    'literal percent escapes' => '%2F%2E%00',
    'single dot' => '.',
    'double dot' => '..',
    'nested dot segments' => 'a/../b',
    'backslash' => 'a\\b',
    'surrounding spaces' => ' key ',
    'control bytes' => "\0line\n\t",
    'maximum length' => str_repeat('é', 1024),
]);

it('preserves the listed string selector and normalizes the other uploaded form fields', function (string $key): void {
    StringActionSource::$key = $key;
    resolve(SourceRegistry::class)->register('StringFixture', StringActionSource::class);
    Gate::define('string-save', static fn (?Authenticatable $user): bool => true);
    $url = $this->postJson('/dapter/data/StringFixture', ['version' => 2])->assertOk()->json('actions.save.target.values.0.url');

    $this->post($url, [
        'record_id' => $key,
        'name' => '  Grace  ',
        'note' => ' ',
        'attachment' => UploadedFile::fake()->createWithContent('sample.txt', 'fixture bytes'),
    ], [...actionHeaders(), 'Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=test'])
        ->assertOk()->assertExactJson(['saved' => $key, 'fields' => ['record_id' => $key, 'name' => 'Grace', 'note' => null], 'upload' => 'fixture bytes']);
    expect(ActionState::$calls)->toBe(1);
})->with(['padded string' => ' key ', 'all whitespace' => " \t ", 'control bytes' => "\0line\n\t", 'numeric-looking string' => ' 001 ']);

it('requires the native form selector in the body and rejects an array or a conflicting upload', function (): void {
    StringActionSource::$key = 'plain';
    StringActionSource::$sources = 0;
    resolve(SourceRegistry::class)->register('StringFixture', StringActionSource::class);
    Gate::define('string-save', static fn (?Authenticatable $user): bool => true);
    $url = '/dapter/actions/StringFixture/save/plain?record_id=plain';
    $headers = [...actionHeaders(), 'Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=test'];

    $this->post($url, [], $headers)->assertUnprocessable();
    $this->post($url, ['record_id' => ['plain']], $headers)->assertUnprocessable();
    $this->call('POST', $url, ['record_id' => 'plain'], [], ['record_id' => UploadedFile::fake()->createWithContent('key.txt', 'plain')], $this->transformHeadersToServerVars($headers))->assertUnprocessable();
    expect(StringActionSource::$sources)->toBe(0)->and(ActionState::$calls)->toBe(0);
});

it('preserves normal form field cleanup on unrelated application routes carrying action headers', function (): void {
    Route::post('/host-form', static fn (Request $request): array => $request->only(['record_id', 'note']));
    $this->post('/host-form', ['record_id' => ' key ', 'note' => ' '], [...actionHeaders(), 'Accept' => 'application/json'])
        ->assertOk()->assertExactJson(['record_id' => 'key', 'note' => null]);
});

it('keeps original action data private when an application route wins over the package pattern', function (): void {
    $host = Route::post('/dapter/actions/Host/ping', static fn (Request $request): array => [
        'route' => $request->route()->getName(),
        'captured' => $request->attributes->has(ActionInput::class),
        'original' => ActionInput::value($request, 'note'),
        'note' => $request->input('note'),
    ])->name('host.ping');
    $routes = new RouteCollection;
    $routes->add($host);
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if ($route !== $host) {
            $routes->add($route);
        }
    }
    Route::setRoutes($routes);

    $this->post('/dapter/actions/Host/ping', ['note' => ' Ada '], [...actionHeaders(), 'Accept' => 'application/json'])
        ->assertOk()->assertExactJson(['route' => 'host.ping', 'captured' => false, 'original' => null, 'note' => 'Ada']);
    expect(ActionState::$calls)->toBe(0);
});

it('discards the original form body when an action pipeline throws', function (): void {
    $request = Request::create('/dapter/actions/ActionFixture/save', 'POST', ['recordId' => ' raw '], server: ['HTTP_X_DAPTER_ACTION' => '1']);
    $request->setRouteResolver(static fn () => Route::getRoutes()->getByName('dapter.action'));
    $failure = new LogicException('The action pipeline stopped.');

    try {
        (new ActionInput)->handle($request, static function (Request $current) use ($failure): never {
            $current->request->set('recordId', 'raw');
            expect(ActionInput::value($current, 'recordId'))->toBe(' raw ');

            throw $failure;
        });
    } catch (LogicException $exception) {
        expect($exception)->toBe($failure);
    }
    expect(ActionInput::value($request, 'recordId'))->toBeNull();
});

it('keeps previously issued string target paths usable', function (): void {
    resolve(SourceRegistry::class)->register('StringFixture', StringActionSource::class);
    Gate::define('string-save', static fn (?Authenticatable $user): bool => true);
    $this->postJson('/dapter/actions/StringFixture/save/a/b%3F%23%25a%C3%A7%C3%A3o', ['record_id' => StringActionSource::$key], actionHeaders())
        ->assertOk()->assertJsonPath('saved', StringActionSource::$key);
});

it('rejects ambiguous or malformed record target URLs before resolving a record', function (): void {
    StringActionSource::$key = '{draft}';
    StringActionSource::$sources = 0;
    resolve(SourceRegistry::class)->register('StringFixture', StringActionSource::class);
    Gate::define('string-save', static fn (?Authenticatable $user): bool => true);
    $base = '/dapter/actions/StringFixture/save';
    $query = 'dapter-target=e2RyYWZ0fQ';
    foreach ([
        $base,
        $base.'/%7Bdraft%7D?'.$query,
        $base.'?dapter-target=',
        $base.'?dapter-target=e2RyYWZ0fQ==',
        $base.'?dapter-target=%20e2RyYWZ0fQ',
        $base.'?dapter-target[]=e2RyYWZ0fQ',
        $base.'?dapter-target=first&'.$query,
        $base.'?dapter-target[]=first&'.$query,
    ] as $url) {
        $this->postJson($url, ['record_id' => StringActionSource::$key], actionHeaders())->assertUnprocessable();
    }
    $this->postJson('/dapter/actions/ActionFixture/inherited?'.$query, [], actionHeaders())->assertUnprocessable();
    expect(StringActionSource::$sources)->toBe(0)->and(ActionState::$calls)->toBe(0);
});

it('rejects forged headers when the native response mode is incompatible', function (): void {
    $url = route('dapter.action', ['source' => 'ActionFixture', 'action' => 'traitSave']);
    $this->patch($url, ['name' => 'Grace'], actionHeaders())->assertStatus(406);
    $this->patchJson($url, ['name' => 'Grace'], actionHeaders('visit'))->assertStatus(406);
    expect(ActionState::$owners)->toBe(0)->and(ActionState::$calls)->toBe(0)->and(ActionState::$forms)->toBe(0);
});

it('rejects unknown registrations and invalid action metadata without constructing owners', function (): void {
    $this->postJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'unknown']), [], actionHeaders())->assertNotFound();
    foreach ([InvalidRulesOnlyActionSource::class, DuplicateActionSource::class, DualActionSource::class] as $class) {
        expect(fn () => (new OptionsResolver)->resolve(new $class))->toThrow(DapterException::class);
    }
    expect(ActionState::$owners)->toBe(0)->and(ActionState::$calls)->toBe(0)->and(ActionState::$forms)->toBe(0);
});

it('uses the selected guard principal for source and action policy without changing the default guard', function (): void {
    config()->set('auth.guards.alternate', ['driver' => 'session', 'provider' => 'users']);
    $user = new class extends User
    {
        /** @use HasFactory<Factory<static>> */
        use HasFactory;
    };
    $user->forceFill(['id' => 42]);
    $this->actingAs($user, 'alternate');
    auth()->shouldUse('web');
    resolve(SourceRegistry::class)->register('GuardFixture', GuardActionSource::class);
    Gate::define('source-guard', static fn (?Authenticatable $principal): bool => $principal?->getAuthIdentifier() === 42);
    Gate::define('guard-save', static fn (?Authenticatable $principal): bool => $principal?->getAuthIdentifier() === 42);
    $this->postJson(route('dapter.action', ['source' => 'GuardFixture', 'action' => 'save']), [], actionHeaders())->assertOk()->assertJsonPath('principal', 42);
    expect(auth()->getDefaultDriver())->toBe('web')->and(ActionState::$calls)->toBe(1);
});

it('keeps action routes behind native web CSRF verification', function (): void {
    app()->instance('env', 'production');
    $this->postJson(route('dapter.action', ['source' => 'ActionFixture', 'action' => 'inherited']), [], actionHeaders())->assertStatus(419);
    expect(ActionState::$owners)->toBe(0)->and(ActionState::$calls)->toBe(0)->and(ActionState::$forms)->toBe(0);
});

it('resolves explicit record selectors through the mandatory Eloquent source and custom model key', function (): void {
    Schema::create('dapter_action_records', function (Blueprint $table): void {
        $table->string('uuid')->primary();
        $table->integer('tenant');
        $table->string('name');
    });
    DB::table('dapter_action_records')->insert([['uuid' => 'a1', 'tenant' => 1, 'name' => 'Lin'], ['uuid' => 'b2', 'tenant' => 2, 'name' => 'Ada']]);
    resolve(SourceRegistry::class)->register('UuidFixture', UuidActionSource::class);
    Gate::define('uuid-save', static fn (mixed $user = null, mixed $record = null): bool => $record instanceof ActionUuidRecord && $record->getAttribute('tenant') === 1);
    $this->postJson(route('dapter.action', ['source' => 'UuidFixture', 'action' => 'save', 'target' => 'a1']), ['record_id' => 'a1'], actionHeaders())->assertOk()->assertJsonPath('saved', 'a1');
    $this->postJson(route('dapter.action', ['source' => 'UuidFixture', 'action' => 'save', 'target' => 'b2']), ['record_id' => 'b2'], actionHeaders())->assertNotFound();
    expect(ActionState::$calls)->toBe(1);
});

it('groups mandatory source predicates and global scopes before resolving each record selector', function (): void {
    Schema::create('dapter_action_records', function (Blueprint $table): void {
        $table->string('uuid')->primary();
        $table->integer('tenant');
        $table->string('name');
    });
    DB::table('dapter_action_records')->insert([['uuid' => 'a1', 'tenant' => 1, 'name' => 'Lin'], ['uuid' => 'b2', 'tenant' => 2, 'name' => 'Ada'], ['uuid' => 'c3', 'tenant' => 3, 'name' => 'Grace'], ['uuid' => 'd4', 'tenant' => 4, 'name' => 'Katherine']]);
    resolve(SourceRegistry::class)->register('UuidFixture', DisjunctiveUuidActionSource::class);
    $checked = [];
    Gate::define('uuid-save', static function (mixed $user = null, mixed $record = null) use (&$checked): bool {
        $checked[] = $record->getKey();

        return $record instanceof ActionUuidRecord && in_array($record->getAttribute('tenant'), [1, 3, 4], true);
    });
    $this->postJson(route('dapter.action', ['source' => 'UuidFixture', 'action' => 'save', 'target' => 'a1']), ['record_id' => 'a1'], actionHeaders())->assertOk()->assertJsonPath('saved', 'a1');
    $this->postJson(route('dapter.action', ['source' => 'UuidFixture', 'action' => 'save', 'target' => 'c3']), ['record_id' => 'c3'], actionHeaders())->assertOk()->assertJsonPath('saved', 'c3');
    $this->postJson(route('dapter.action', ['source' => 'UuidFixture', 'action' => 'save', 'target' => 'd4']), ['record_id' => 'd4'], actionHeaders())->assertOk()->assertJsonPath('saved', 'd4');
    $this->postJson(route('dapter.action', ['source' => 'UuidFixture', 'action' => 'save', 'target' => 'b2']), ['record_id' => 'b2'], actionHeaders())->assertNotFound();
    expect($checked)->toBe(['a1', 'c3', 'd4'])->and(ActionState::$calls)->toBe(3);
});

it('resolves a qualified selector and its output alias on a distinct joined record source', function (): void {
    Schema::create('dapter_action_records', function (Blueprint $table): void {
        $table->string('uuid')->primary();
        $table->integer('tenant');
        $table->string('name');
    });
    DB::table('dapter_action_records')->insert(['uuid' => 'a1', 'tenant' => 1, 'name' => 'Lin']);
    resolve(SourceRegistry::class)->register('ProjectedFixture', ProjectedUuidActionSource::class);
    Gate::define('uuid-save', static fn (?Authenticatable $user, ActionUuidRecord $record): bool => $record->getAttribute('public_name') === 'Lin');
    $this->postJson(route('dapter.action', ['source' => 'ProjectedFixture', 'action' => 'save', 'target' => 'Lin']), ['record_id' => 'Lin'], actionHeaders())
        ->assertOk()->assertExactJson(['saved' => 'a1']);
    expect(ActionState::$calls)->toBe(1);
});

it('rejects mandatory sources that cannot preserve record membership before executing lookup SQL', function (Closure $constrain, bool $scoped): void {
    Schema::create('dapter_action_records', function (Blueprint $table): void {
        $table->string('uuid')->primary();
        $table->integer('tenant');
    });
    DB::table('dapter_action_records')->insert([['uuid' => 'a1', 'tenant' => 1], ['uuid' => 'b2', 'tenant' => 1]]);
    BoundedUuidActionSource::$constrain = $scoped ? static fn (Builder $query): Builder => $query->withGlobalScope('source-shape', $constrain) : $constrain;
    resolve(SourceRegistry::class)->register('BoundedFixture', BoundedUuidActionSource::class);
    Gate::define('uuid-save', static fn (?Authenticatable $user): bool => true);
    Exceptions::fake([DapterException::class]);
    DB::enableQueryLog();
    $this->postJson(route('dapter.action', ['source' => 'BoundedFixture', 'action' => 'save', 'target' => 'a1']), ['record_id' => 'a1'], actionHeaders())->assertStatus(500);
    Exceptions::assertReported(static fn (DapterException $e): bool => $e->errorCode === 'invalid_source');
    expect(DB::getQueryLog())->toBeEmpty()->and(ActionState::$calls)->toBe(0);
})->with([
    'limit' => [static fn (Builder $query): Builder => $query->limit(1)],
    'offset' => [static fn (Builder $query): Builder => $query->offset(1)],
    'per-group limit' => [static fn (Builder $query): Builder => $query->groupLimit(1, 'tenant')],
    'union limit' => [static fn (Builder $query): Builder => $query->union(ActionUuidRecord::query())->limit(1)],
    'union offset' => [static fn (Builder $query): Builder => $query->union(ActionUuidRecord::query())->offset(1)],
    'group by' => [static fn (Builder $query): Builder => $query->select('tenant')->groupBy('tenant')],
    'having' => [static fn (Builder $query): Builder => $query->having('tenant', '=', 1)],
    'raw projection' => [static fn (Builder $query): Builder => $query->selectRaw('uuid, tenant')],
    'builder aggregate' => [static function (Builder $query): Builder {
        $query->getQuery()->aggregate = ['function' => 'min', 'columns' => ['uuid']];

        return $query;
    }],
    'deferred query callback' => [static fn (Builder $query): Builder => $query->beforeQuery(static fn (QueryBuilder $base): QueryBuilder => $base->orderBy('tenant'))],
    'clone callback' => [static fn (Builder $query): Builder => $query->onClone(static fn (Builder $clone): Builder => $clone->orderBy('tenant'))],
    'Eloquent result callback' => [static fn (Builder $query): Builder => $query->afterQuery(static fn (Collection $rows): Collection => $rows->take(1))],
    'base query result callback' => [static function (Builder $query): Builder {
        $query->getQuery()->afterQuery(static fn (Collection $rows): Collection => $rows->take(1));

        return $query;
    }],
])->with(['source' => false, 'global scope' => true]);

it('rejects a record clone callback before it runs and honors the same hook on a source action', function (): void {
    Schema::create('dapter_action_records', function (Blueprint $table): void {
        $table->string('uuid')->primary();
        $table->integer('tenant');
    });
    DB::table('dapter_action_records')->insert([['uuid' => 'a1', 'tenant' => 1], ['uuid' => 'b2', 'tenant' => 1]]);
    $clones = 0;
    BoundedUuidActionSource::$constrain = static function (Builder $query) use (&$clones): Builder {
        return $query->onClone(static function (Builder $copy) use (&$clones): void {
            $clones++;
            $copy->limit(1);
        });
    };
    expect((new BoundedUuidActionSource)->source()->pluck('uuid')->all())->toBe(['a1', 'b2']);
    resolve(SourceRegistry::class)->register('CloneFixture', BoundedUuidActionSource::class);
    Gate::define('source-summary', static fn (?Authenticatable $user): bool => true);
    Exceptions::fake([DapterException::class]);
    DB::enableQueryLog();
    $this->postJson(route('dapter.action', ['source' => 'CloneFixture', 'action' => 'save', 'target' => 'a1']), ['record_id' => 'a1'], actionHeaders())->assertStatus(500);
    Exceptions::assertReported(static fn (DapterException $e): bool => $e->errorCode === 'invalid_source');
    expect($clones)->toBe(0)->and(DB::getQueryLog())->toBeEmpty()->and(ActionState::$calls)->toBe(0);

    $this->postJson(route('dapter.action', ['source' => 'CloneFixture', 'action' => 'summary']), [], actionHeaders())->assertOk()->assertExactJson(['ids' => ['a1']]);
    expect($clones)->toBe(2);
});

it('rejects a report even when its returned identity is requested', function (Closure $report): void {
    Schema::create('dapter_action_records', function (Blueprint $table): void {
        $table->string('uuid')->primary();
        $table->integer('tenant');
    });
    DB::table('dapter_action_records')->insert([['uuid' => 'a1', 'tenant' => 1], ['uuid' => 'b2', 'tenant' => 1]]);
    BoundedUuidActionSource::$constrain = $report;
    resolve(SourceRegistry::class)->register('ReportFixture', BoundedUuidActionSource::class);
    $rows = (new BoundedUuidActionSource)->source()->get();
    expect($rows->modelKeys())->toBe(['a1']);

    Exceptions::fake([DapterException::class]);
    DB::enableQueryLog();
    $this->postJson(route('dapter.action', ['source' => 'ReportFixture', 'action' => 'save', 'target' => 'a1']), ['record_id' => 'a1'], actionHeaders())->assertStatus(500);
    Exceptions::assertReported(static fn (DapterException $e): bool => $e->errorCode === 'invalid_source');
    expect(DB::getQueryLog())->toBeEmpty()->and(ActionState::$calls)->toBe(0);
})->with([
    'grouped minimum' => [static fn (Builder $query): Builder => $query->selectRaw('MIN(uuid) AS uuid')->groupBy('tenant')],
    'minimum with HAVING' => [static fn (Builder $query): Builder => $query->selectRaw('MIN(uuid) AS uuid')->havingRaw('COUNT(*) = 2')],
    'global minimum' => [static fn (Builder $query): Builder => $query->selectRaw('MIN(uuid) AS uuid')],
]);

it('rejects a PostgreSQL DISTINCT ON record source before running its query', function (): void {
    BoundedUuidActionSource::$constrain = static function (Builder $query): Builder {
        $connection = DB::connection();
        $base = new QueryBuilder($connection, new PostgresGrammar($connection));

        return $query->setQuery($base->from('dapter_action_records')->distinct('tenant'));
    };
    resolve(SourceRegistry::class)->register('DistinctFixture', BoundedUuidActionSource::class);
    Exceptions::fake([DapterException::class]);
    DB::enableQueryLog();
    $this->postJson(route('dapter.action', ['source' => 'DistinctFixture', 'action' => 'save', 'target' => 'a1']), ['record_id' => 'a1'], actionHeaders())->assertStatus(500);
    Exceptions::assertReported(static fn (DapterException $e): bool => $e->errorCode === 'invalid_source');
    expect(DB::getQueryLog())->toBeEmpty()->and(ActionState::$calls)->toBe(0);
});

it('accepts only canonical integer multipart selectors and never coerces a JSON string selector', function (): void {
    $url = route('dapter.action', ['source' => 'ActionFixture', 'action' => 'save', 'target' => 1]);
    $this->post($url, ['id' => '1', 'name' => 'Grace'], [...actionHeaders(), 'Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=test'])->assertOk()->assertJsonPath('saved', 1);
    $this->post($url, ['id' => '01', 'name' => 'Grace'], [...actionHeaders(), 'Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=test'])->assertUnprocessable();
    $this->post($url, ['id' => ' 1 ', 'name' => 'Grace'], [...actionHeaders(), 'Accept' => 'application/json', 'Content-Type' => 'multipart/form-data; boundary=test'])->assertUnprocessable();
    $this->postJson($url, ['id' => '1', 'name' => 'Grace'], actionHeaders())->assertUnprocessable();
    expect(ActionState::$calls)->toBe(1)->and(ActionState::$forms)->toBe(1);
});

it('validates the original JSON selector before native trimming can change strict equality', function (): void {
    $class = StringActionSource::class;
    resolve(SourceRegistry::class)->register('StrictStringFixture', $class);
    Gate::define('string-save', static fn (mixed $user = null, mixed $record = null, mixed $context = null): bool => true);
    $url = route('dapter.action', ['source' => 'StrictStringFixture', 'action' => 'save', 'target' => $class::$key]);
    $this->postJson($url, ['record_id' => ' '.$class::$key.' '], actionHeaders())->assertUnprocessable();
    $integerUrl = route('dapter.action', ['source' => 'ActionFixture', 'action' => 'save', 'target' => 1]);
    $this->call('POST', $integerUrl, [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_DAPTER_ACTION' => '1', 'HTTP_X_DAPTER_TRANSPORT' => 'http'], '{"id":1.0,"name":"Grace"}')->assertUnprocessable();
    expect(ActionState::$calls)->toBe(0)->and(ActionState::$forms)->toBe(0);
});

it('replaces whole policies through config method attributes and fluent source overrides', function (): void {
    config()->set('dapter.declarations', ['action_policies' => ['inherited' => ['ability' => 'configured', 'arguments' => ['low']], 'traitSave' => ['ability' => 'configured']]]);
    $registry = new ActionRegistry;
    $parent = new ActionParentSource;
    $definitions = $registry->discover($parent, (new OptionsResolver)->resolve($parent));
    expect($definitions['inherited']->policy->ability)->toBe('parent-save')->and($definitions['inherited']->policy->arguments)->toBe([])->and($definitions['traitSave']->policy->ability)->toBe('external');
    $fluent = new class extends ActionParentSource
    {
        public function options(): StoreOptions
        {
            return parent::options()->actionAuthorize('traitSave', 'fluent', ['high']);
        }
    };
    $definitions = $registry->discover($fluent, (new OptionsResolver)->resolve($fluent));
    expect($definitions['traitSave']->policy->ability)->toBe('fluent')->and($definitions['traitSave']->policy->arguments)->toBe(['high'])->and($definitions['traitSave']->policy->target)->toBeNull()->and(ActionState::$owners)->toBe(0);
});

it('keeps missing action sources native 404 responses', function (string $source): void {
    $this->postJson(route('dapter.action', ['source' => $source, 'action' => 'save']), [], actionHeaders())->assertNotFound()->assertJsonMissingPath('version');
    expect(ActionState::$owners)->toBe(0)->and(ActionState::$calls)->toBe(0)->and(ActionState::$forms)->toBe(0);
})->with(['MissingActionFixture', 'invalid-name']);

it('leaves exceptions thrown by the action handler to the native exception renderer', function (): void {
    $source = new #[PublicAccess] class extends CollectionDataSource
    {
        public function source(): array
        {
            return [];
        }

        #[Action(transports: [ActionTransport::Http])]
        #[ActionAuthorize('save')]
        private function fail(): never
        {
            throw DapterException::missing();
        }
    };
    resolve(SourceRegistry::class)->register('NativeExceptionFixture', $source::class);
    $this->postJson(route('dapter.action', ['source' => 'NativeExceptionFixture', 'action' => 'fail']), [], actionHeaders())->assertStatus(500)->assertJsonMissingPath('version');
});
