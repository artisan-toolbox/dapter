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

it('links the README badges to releases and Packagist', function () {
    [$version, $packagist] = (new Dapter)->readmeHead()->badges();

    expect($version->link())->toBe('https://github.com/artisan-toolbox/dapter/releases')
        ->and($version->imgSrc('1.2.3-beta.4'))->toBe('https://img.shields.io/badge/version-1.2.3--beta.4-blue?style=flat-square')
        ->and($packagist->link())->toBe('https://packagist.org/packages/artisan-toolbox/dapter')
        ->and($packagist->imgSrc())->toBe('https://img.shields.io/badge/Packagist-F28D1A?style=flat-square&logo=packagist&logoColor=white');
});
