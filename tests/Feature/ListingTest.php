<?php

declare(strict_types=1);

use App\DataSources\PublicRecords;

require_once __DIR__.'/../Fixtures/ListingSources.php';

it('reads a source through the public JSON route with authorized defaults', function () {
    $this->postJson('/dapter/data/PublicRecords', ['version' => 1])
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Notebook')
        ->assertJsonPath('paging.total', 2)
        ->assertJsonPath('requested.paging.per_page', 10)
        ->assertJsonPath('state.order.kind', 'source');
});

it('rejects invalid raw JSON before reading the source', function () {
    PublicRecords::$reads = 0;
    $this->call('POST', '/dapter/data/PublicRecords', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], content: '{')
        ->assertStatus(400)->assertJsonPath('error.kind', 'validation');
    expect(PublicRecords::$reads)->toBe(0);
});

it('counts only the fixed source scope and projects top-level Arrayable rows before disclosure', function () {
    $this->postJson('/dapter/data/ScopedRecords', ['version' => 1])->assertOk()
        ->assertJsonPath('data', [['name' => 'North notebook', 'studio' => 'studio-north']])
        ->assertJsonPath('paging.total', 1)->assertJsonPath('paging.filtered', 1)
        ->assertDontSee('South sketchbook')->assertDontSee('private_note')->assertDontSee('Fixture-only note');
});
