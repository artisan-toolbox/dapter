<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Broadcasting\UpdateEnvelope;
use ArtisanToolbox\Dapter\Http\DapterException;

it('agrees with the distributed update envelope corpus at the PHP boundary', function () {
    $corpus = json_decode(resolve('files')->get(__DIR__.'/../../resources/contracts/updates-v1.json'), false, 512, JSON_THROW_ON_ERROR);
    expect($corpus->version)->toBe(1);
    foreach ($corpus->cases as $case) {
        if ($case->valid) {
            expect(UpdateEnvelope::parse($case->envelope)['source'])->toBe($case->envelope->source);
        } else {
            expect(fn () => UpdateEnvelope::parse($case->envelope))->toThrow(DapterException::class);
        }
    }
});
