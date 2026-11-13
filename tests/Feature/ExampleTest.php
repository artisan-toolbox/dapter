<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Dapter;

it('registers the package as a shared container binding', function () {
    expect(app()->isShared(Dapter::class))->toBeTrue();
});

it('returns the same instance from the container', function () {
    expect(resolve(Dapter::class))->toBe(resolve(Dapter::class));
});
