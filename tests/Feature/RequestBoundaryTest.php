<?php

declare(strict_types=1);

use App\DataSources\OptionRecords;
use ArtisanToolbox\Dapter\StoreOptions;

require_once __DIR__.'/../Fixtures/ListingSources.php';

beforeEach(function () {
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    OptionRecords::$reads = 0;
    OptionRecords::$rows = [];
});

it('rejects malformed envelope and unsupported intent without reading records', function (string $json, string $kind) {
    $this->call('POST', '/dapter/data/OptionRecords', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $json)
        ->assertStatus(422)->assertJsonPath('error.kind', $kind)->assertJsonPath('errors._dapter_kind', [$kind]);
    expect(OptionRecords::$reads)->toBe(0);
})->with([
    ['null', 'validation'], ['[]', 'validation'], ['true', 'validation'], ['"version"', 'validation'],
    ['{}', 'validation'], ['{"version":2}', 'validation'], ['{"version":1.0}', 'validation'], ['{"version":1,"extra":true}', 'validation'],
    ['{"version":1,"\u0000unexpected":1}', 'validation'],
    ['{"version":1,"state":null}', 'validation'], ['{"version":1,"state":[]}', 'validation'],
    ['{"version":1,"state":{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":1,"per_page":7}}}', 'validation'],
    ['{"version":1,"state":{"version":1,"fields":[],"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":1,"per_page":10}}}', 'validation'],
    ['{"version":1,"state":{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":1.0,"per_page":10}}}', 'validation'],
    ['{"version":1,"state":{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":"1","per_page":10}}}', 'validation'],
    ['{"version":1,"state":{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":true,"per_page":10}}}', 'validation'],
    ['{"version":1,"state":{"version":1,"fields":{"x":{"unsafe":9007199254740992}},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":1,"per_page":10}}}', 'validation'],
    ['{"version":1,"state":{"version":1,"fields":{"name":{"search":" value "}},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":1,"per_page":10}}}', 'unsupported_operation'],
    ['{"version":1,"state":{"version":1,"fields":{},"global":{"search":""},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":1,"per_page":10}}}', 'unsupported_operation'],
    ['{"version":1,"state":{"version":1,"fields":{},"global":{},"searchMatch":"any","order":{"kind":"default"},"paging":{"mode":"offset","current":1,"per_page":10}}}', 'unsupported_operation'],
    ['{"version":1,"state":{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"custom"},"paging":{"mode":"offset","current":1,"per_page":10}}}', 'unsupported_operation'],
    ['{"version":1,"state":{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"cursor","current":1,"per_page":10}}}', 'unsupported_operation'],
]);

it('authorizes before checking supported source-specific intent', function () {
    OptionRecords::$settings = StoreOptions::make();
    $json = '{"version":1,"state":{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":1,"per_page":7}}}';
    $this->call('POST', '/dapter/data/OptionRecords', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $json)
        ->assertUnauthorized()->assertJsonPath('error.code', 'authentication_required')->assertJsonMissingPath('capabilities');
    expect(OptionRecords::$reads)->toBe(0);
});

it('clamps safe large positions and returns dense bounded pages without requiring row ids', function () {
    OptionRecords::$rows = [4 => ['name' => 'one'], 9 => ['name' => 'two'], 14 => ['name' => 'three'], 18 => ['name' => 'four'], 20 => ['name' => 'five'], 80 => ['name' => 'six']];
    $state = json_decode('{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":9007199254740991,"per_page":5}}', flags: JSON_THROW_ON_ERROR);
    $response = $this->postJson('/dapter/data/OptionRecords', ['version' => 1, 'state' => $state])->assertOk()
        ->assertExactJson([
            'version' => 1, 'data' => [['name' => 'six']],
            'requested' => ['version' => 1, 'fields' => [], 'global' => [], 'searchMatch' => 'all', 'order' => ['kind' => 'default'], 'paging' => ['mode' => 'offset', 'current' => 2, 'per_page' => 5]],
            'state' => ['order' => ['kind' => 'source'], 'paging' => ['mode' => 'offset', 'current' => 2, 'per_page' => 5]],
            'paging' => ['mode' => 'offset', 'current' => 2, 'pages' => 2, 'per_page' => 5, 'total' => 6, 'filtered' => 6, 'has_previous' => true, 'has_next' => false],
            'capabilities' => ['fields' => [], 'global' => ['multi_order' => false, 'multi_order_limit' => 1, 'searchMatch' => ['default' => 'all', 'allowed' => ['all']], 'filters' => null, 'search' => null, 'order' => null], 'paging' => ['mode' => 'offset', 'per_page' => 10, 'per_page_options' => [5, 10, 50, 100]]],
        ]);
    expect($response->json('data'))->toBeList()
        ->and(json_decode($response->content(), flags: JSON_THROW_ON_ERROR)->requested->fields)->toBeInstanceOf(stdClass::class);
});

it('keeps empty sources at the first page and resets absent intent to current defaults', function () {
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()
        ->assertJsonPath('data', [])->assertJsonPath('paging.current', 1)->assertJsonPath('paging.pages', 1)
        ->assertJsonPath('paging.total', 0)->assertJsonPath('paging.has_next', false)->assertJsonPath('paging.has_previous', false);
});

it('serializes only the bounded page and preserves nested JSON values', function () {
    $resource = fopen('php://memory', 'r');

    throw_if($resource === false, RuntimeException::class, 'Cannot create a test stream.');
    OptionRecords::$rows = [['object' => (object) ['empty' => (object) [], 'list' => [], 'null' => null, 'false' => false, 'zero' => 0, 'space' => '  value  ']], 1, 2, 3, 4, $resource];
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->pageSize(5);
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()->assertJsonPath('data.0.object.space', '  value  ')->assertJsonPath('data.0.object.false', false);
    fclose($resource);
});

it('sanitizes invalid row output instead of leaking objects or exceptions', function (string $case) {
    $value = match ($case) {
        'object' => new DateTimeImmutable,
        'integer' => 9007199254740992,
        'float' => INF,
        'utf8' => "\xB1",
        default => null,
    };
    OptionRecords::$rows = [['value' => $value]];
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertStatus(500)->assertJsonPath('error.kind', 'server')->assertJsonMissingPath('data');
})->with(['object', 'integer', 'float', 'utf8']);

it('classifies well-formed JSON deeper than the protocol limit as validation rather than malformed syntax', function () {
    $json = '{"version":1,"state":'.str_repeat('[', 600).'0'.str_repeat(']', 600).'}';
    $this->call('POST', '/dapter/data/OptionRecords', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: $json)
        ->assertStatus(422)->assertJsonPath('errors._dapter_kind', ['validation']);
    expect(OptionRecords::$reads)->toBe(0);
});

it('rejects nested custom object serialization before encoding the response', function () {
    OptionRecords::$rows = [['value' => new class extends stdClass implements JsonSerializable
    {
        public function jsonSerialize(): mixed
        {
            return ['unsafe' => 9007199254740992];
        }
    }]];

    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])
        ->assertStatus(500)->assertJsonPath('error.kind', 'server')->assertJsonMissingPath('data');
});

it('preserves ordinary finite decimal values in record payloads', function () {
    OptionRecords::$rows = [['price' => 1.5, 'details' => (object) ['discount' => 0.25]]];

    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])
        ->assertOk()->assertJsonPath('data.0.price', 1.5)->assertJsonPath('data.0.details.discount', 0.25);
});

it('reads scalar Collection pages without a durable row identity', function () {
    OptionRecords::$rows = [4 => 'north', 9 => 0, 14 => false, 18 => null, 20 => 1.5, 80 => 'south'];
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->pageSize(5);

    $this->postJson('/dapter/data/OptionRecords', ['version' => 1])->assertOk()
        ->assertJsonPath('data', ['north', 0, false, null, 1.5])
        ->assertJsonPath('paging.total', 6)->assertJsonPath('paging.has_next', true);

    $state = json_decode('{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":2,"per_page":5}}', flags: JSON_THROW_ON_ERROR);
    $this->postJson('/dapter/data/OptionRecords', ['version' => 1, 'state' => $state])->assertOk()
        ->assertJsonPath('data', ['south'])->assertJsonPath('paging.has_previous', true)
        ->assertJsonMissingPath('metadata');
});
