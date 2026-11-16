<?php

declare(strict_types=1);

use App\DataSources\FixtureRecord;
use App\DataSources\OptionRecords;
use App\DataSources\SqlRecords;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Fixtures/ListingSources.php';

beforeEach(function () {
    FixtureRecord::clearBootedModels();
    SqlRecords::$builder = null;
    OptionRecords::$rows = [];
    OptionRecords::$settings = StoreOptions::make()->publicAccess()->pageSize(5);

    Schema::create('fixture_records', function (Blueprint $table): void {
        $table->id();
        $table->integer('tenant');
        $table->string('name');
        $table->string('secret');
    });
});

afterEach(function () {
    OptionRecords::$settings = null;
    OptionRecords::$rows = [];
    SqlRecords::$builder = null;
});

it('returns exact empty base counts and clamps the page without navigation', function (string $source) {
    $state = json_decode('{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":99,"per_page":5}}', flags: JSON_THROW_ON_ERROR);

    $this->postJson('/dapter/data/'.$source, ['version' => 1, 'state' => $state])
        ->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('paging.total', 0)
        ->assertJsonPath('paging.filtered', 0)
        ->assertJsonPath('paging.current', 1)
        ->assertJsonPath('paging.pages', 1)
        ->assertJsonPath('paging.has_previous', false)
        ->assertJsonPath('paging.has_next', false)
        ->assertJsonPath('requested.paging', ['mode' => 'offset', 'current' => 1, 'per_page' => 5])
        ->assertJsonPath('state.paging', ['mode' => 'offset', 'current' => 1, 'per_page' => 5])
        ->assertJsonPath('requested.order', ['kind' => 'default'])
        ->assertJsonPath('state.order', ['kind' => 'source']);
})->with(['Collection' => 'OptionRecords', 'SQLite' => 'SqlRecords']);

it('keeps tied source order stable across adjacent SQLite page boundaries', function () {
    foreach ([7, 3, 6, 2, 5, 1, 4] as $id) {
        FixtureRecord::query()->create(['id' => $id, 'tenant' => 1, 'name' => 'Same name', 'secret' => 'hidden']);
    }
    SqlRecords::$builder = FixtureRecord::query()->where('tenant', 1)->orderBy('name');

    $first = $this->postJson('/dapter/data/SqlRecords', ['version' => 1])->assertOk()
        ->assertJsonPath('paging.total', 7)->assertJsonPath('paging.filtered', 7)
        ->assertJsonPath('paging.pages', 2)->assertJsonPath('paging.has_previous', false)
        ->assertJsonPath('paging.has_next', true)->json('data');

    $state = json_decode('{"version":1,"fields":{},"global":{},"searchMatch":"all","order":{"kind":"default"},"paging":{"mode":"offset","current":2,"per_page":5}}', flags: JSON_THROW_ON_ERROR);
    $second = $this->postJson('/dapter/data/SqlRecords', ['version' => 1, 'state' => $state])->assertOk()
        ->assertJsonPath('paging.total', 7)->assertJsonPath('paging.filtered', 7)
        ->assertJsonPath('paging.current', 2)->assertJsonPath('paging.has_previous', true)
        ->assertJsonPath('paging.has_next', false)->json('data');

    expect(array_column($first, 'id'))->toBe([1, 2, 3, 4, 5])
        ->and(array_column($second, 'id'))->toBe([6, 7]);
});
