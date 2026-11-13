<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Dapter;
use Illuminate\Support\Facades\File;

it('references a real portable logo in the README metadata', function () {
    $logo = (new Dapter)->readmeHead()->logo();

    expect($logo)->not->toBeNull();
    expect(File::get(dirname(__DIR__, 2).'/'.$logo['src']))
        ->toContain('<svg', '<title id="title">Dapter icon</title>');
});
