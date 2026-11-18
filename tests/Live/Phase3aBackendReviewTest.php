<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Broadcasting\LiveSourceRegistration;
use ArtisanToolbox\Dapter\Broadcasting\PrivateChannel;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\StoreOptions;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveAudience;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveContexts;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveRows;
use ArtisanToolbox\Dapter\Tests\Fixtures\LiveTestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Broadcasting\Factory;

require_once __DIR__.'/../Fixtures/LiveSources.php';
uses(LiveTestCase::class);

it('rejects existing Laravel regex patterns that authorize a protected live name', function (): void {
    $broadcaster = resolve(Factory::class)->connection();
    $broadcaster->channel('(review|other).rows.{token}', static fn (): bool => true, ['guards' => ['web']]);
    $registry = new SourceRegistry;
    $options = StoreOptions::make()->guard('live')->broadcastUpdatable(channel: new PrivateChannel('review.rows.{context}', LiveAudience::class));
    $registry->registerLive('ReviewRows', new LiveSourceRegistration(LiveRows::class, $options, LiveContexts::class, 'review-v1'));

    expect(fn () => $registry->seal())->toThrow(DapterException::class)
        ->and($broadcaster->getChannels()->has('review.rows.{context}'))->toBeFalse();
});

it('preserves unrelated regex registrations when no live source is registered', function (): void {
    $broadcaster = resolve(Factory::class)->connection();
    $broadcaster->channel('(review|other).rows.{token}', static fn (): bool => true, ['guards' => ['web']]);
    $registry = new SourceRegistry;
    $registry->seal();
    $this->actingAs(new GenericUser(['id' => 99]), 'web');

    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-review.rows.'.str_repeat('A', 43)])->assertOk();
});

it('permits disjoint canonical existing Laravel channels before installing live callbacks', function (): void {
    $broadcaster = resolve(Factory::class)->connection();
    $broadcaster->channel('application.records.{record}', static fn (): bool => true, ['guards' => ['web']]);
    $registry = new SourceRegistry;
    $options = StoreOptions::make()->guard('live')->broadcastUpdatable(channel: new PrivateChannel('review.rows.{context}', LiveAudience::class));
    $registry->registerLive('ReviewRows', new LiveSourceRegistration(LiveRows::class, $options, LiveContexts::class, 'review-v1'));
    $registry->seal();

    expect($broadcaster->getChannels()->has('review.rows.{context}'))->toBeTrue();
    $this->actingAs(new GenericUser(['id' => 99]), 'web');
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-review.rows.'.str_repeat('A', 43)])->assertForbidden();
    $this->postJson('/broadcasting/auth', ['channel_name' => 'private-application.records.7'])->assertOk();
});

it('rejects overlapping canonical Laravel patterns before installing any live callback', function (): void {
    $broadcaster = resolve(Factory::class)->connection();
    $broadcaster->channel('review.{group}.fixed', static fn (): bool => true, ['guards' => ['web']]);
    $registry = new SourceRegistry;
    $options = StoreOptions::make()->guard('live')->broadcastUpdatable(channel: new PrivateChannel('review.rows.{context}', LiveAudience::class));
    $registry->registerLive('ReviewRows', new LiveSourceRegistration(LiveRows::class, $options, LiveContexts::class, 'review-v1'));

    expect(fn () => $registry->seal())->toThrow(DapterException::class)
        ->and($broadcaster->getChannels()->has('review.rows.{context}'))->toBeFalse();
});
