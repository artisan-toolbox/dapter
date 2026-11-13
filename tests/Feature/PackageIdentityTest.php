<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Dapter;
use ArtisanToolbox\Dapter\Facades\Dapter as DapterFacade;
use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeHead;
use Illuminate\Support\Facades\File;

it('exposes the package identity through the container and facade', function () {
    $dapter = resolve(Dapter::class);

    expect(DapterFacade::getFacadeRoot())->toBeInstanceOf(Versionable::class)->toBeInstanceOf(WithReadmeHead::class)
        ->and(DapterFacade::getFacadeRoot())->toBe($dapter)
        ->and(Dapter::VERSION)->toMatch('/^\d+\.\d+\.\d+(?:-[\w.]+)?$/');
});

it('keeps the PHP and frontend package versions synchronized', function () {
    $package = json_decode(File::get(dirname(__DIR__, 2).'/package.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($package['version'])->toBe(Dapter::VERSION);
});
