<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Dapter;

it('resolves the singleton', function () {
    expect(app(Dapter::class))->toBeInstanceOf(Dapter::class);
});

it('returns the same instance from the container', function () {
    expect(app(Dapter::class))->toBe(app(Dapter::class));
});
