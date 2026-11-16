<?php

declare(strict_types=1);

use App\DataSources\FixtureRecord;
use App\DataSources\FreshRecords;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

require_once __DIR__.'/../Fixtures/ListingSources.php';

it('registers the fixed web route and publishable cacheable defaults', function () {
    $route = Route::getRoutes()->getByName('dapter.data');
    expect($route)->not->toBeNull()
        ->and($route->methods())->toBe(['POST'])
        ->and($route->gatherMiddleware())->toBe(['web'])
        ->and(config('dapter.paging.mode'))->toBe(PagingMode::Offset)
        ->and(config('dapter.access.guard'))->toBe('web');
    File::delete(config_path('dapter.php'));

    try {
        expect(\Orchestra\Testbench\artisan($this, 'vendor:publish', ['--tag' => 'dapter-config']))->toBe(0);
        expect(File::get(config_path('dapter.php')))->toBe(File::get(dirname(__DIR__, 2).'/config/dapter.php'));
        expect(\Orchestra\Testbench\artisan($this, 'config:cache'))->toBe(0);

        $cached = require app()->getCachedConfigPath();
        expect($cached['dapter']['paging']['mode'])->toBe(PagingMode::Offset)
            ->and($cached['dapter']['paging']['per_page'])->toBe(10)
            ->and($cached['dapter']['paging']['per_page_options'])->toBe([5, 10, 50, 100]);
    } finally {
        expect(\Orchestra\Testbench\artisan($this, 'config:clear'))->toBe(0);
        File::delete(config_path('dapter.php'));
    }
});

it('generates a source-only Collection class and reads it through the package route', function () {
    $path = app_path('DataSources/Catalog/GeneratedCollection.php');
    File::delete($path);

    try {
        expect(\Orchestra\Testbench\artisan($this, 'make:datasource Catalog.GeneratedCollection'))->toBe(0);
        require_once $path;
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->postJson('/dapter/data/Catalog.GeneratedCollection', ['version' => 1])->assertOk()->assertJsonPath('data', [])->assertJsonPath('paging.total', 0);
        $original = File::get($path);
        expect(\Orchestra\Testbench\artisan($this, 'make:datasource Catalog.GeneratedCollection'))->toBe(1);
        expect(File::get($path))->toBe($original);
    } finally {
        File::delete($path);
    }
});

it('generates an Eloquent scaffold requiring an application-owned model', function () {
    $path = app_path('DataSources/Catalog/GeneratedEloquent.php');
    File::delete($path);

    try {
        expect(\Orchestra\Testbench\artisan($this, 'make:datasource Catalog.GeneratedEloquent --eloquent'))->toBe(0);
        expect(File::get($path))->toContain('extends EloquentDataSource', 'Implement source() with your application model query');
        $contents = str_replace("throw new LogicException('Implement source() with your application model query before reading this source.');", 'return \\App\\DataSources\\FixtureRecord::query();', File::get($path));
        File::put($path, $contents);
        require_once $path;
        Schema::create('fixture_records', static function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        FixtureRecord::clearBootedModels();
        FixtureRecord::query()->create(['id' => 1, 'name' => 'Generated fixture']);
        $this->actingAs(new GenericUser(['id' => 1]));
        $this->postJson('/dapter/data/Catalog.GeneratedEloquent', ['version' => 1])->assertOk()->assertJsonPath('data.0.name', 'Generated fixture');
    } finally {
        File::delete($path);
    }
});

it('rejects source names outside the conventional namespace', function (string $name) {
    expect(\Orchestra\Testbench\artisan($this, 'make:datasource', ['name' => $name]))->toBe(1);
})->with(['../Products', 'Catalog..Products', 'App\\DataSources\\Products', '/Products', 'Products/Other', '', 'Class', 'int']);

it('constructs a fresh source even when the application binds it as a singleton', function () {
    FreshRecords::$constructed = 0;
    app()->singleton(FreshRecords::class);
    resolve(FreshRecords::class);
    $this->postJson('/dapter/data/FreshRecords', ['version' => 1])->assertOk()->assertJsonPath('data.0.generation', 2)->assertJsonPath('data.0.source_calls', 1);
    $this->postJson('/dapter/data/FreshRecords', ['version' => 1])->assertOk()->assertJsonPath('data.0.generation', 3)->assertJsonPath('data.0.source_calls', 1);
});

it('uses the selected principal during source execution without changing the default guard', function () {
    config()->set('auth.guards.alternate', config('auth.guards.web'));
    $this->actingAs(new GenericUser(['id' => 1]), 'web');
    $this->actingAs(new GenericUser(['id' => 2]), 'alternate');
    auth()->shouldUse('web');
    $this->postJson('/dapter/data/ReaderRecords', ['version' => 1])->assertOk()
        ->assertJsonPath('data.0.source_reader', 2)->assertJsonPath('data.0.row.reader', 2);
    expect(auth()->getDefaultDriver())->toBe('web');
});
