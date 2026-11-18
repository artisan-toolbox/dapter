<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\EnumBinding;
use ArtisanToolbox\Dapter\Options\HandlerOptions;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use ArtisanToolbox\Dapter\Support\TrustedHandlerBinder;
use ArtisanToolbox\Dapter\Tests\Fixtures\BindingLifecycleSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\DeclarationElementsFilter;
use ArtisanToolbox\Dapter\Tests\Fixtures\DeclarationFilter;
use ArtisanToolbox\Dapter\Tests\Fixtures\DeclarationFlag;
use ArtisanToolbox\Dapter\Tests\Fixtures\DeclarationStatus;
use ArtisanToolbox\Dapter\Tests\Fixtures\OtherDeclarationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

require_once __DIR__.'/../Fixtures/AdvancedDeclarationSources.php';

it('validates every enum element and null before constructing a handler owner', function (): void {
    DeclarationFilter::$owners = 0;
    $binder = new TrustedHandlerBinder;
    $handler = $binder->describe(DeclarationElementsFilter::class);
    $binding = new HandlerOptions(new EnumBinding(DeclarationStatus::class, elements: true));
    expect($binder->bindValue($handler, ['pending', 'active'], $binding))->toBe([DeclarationStatus::Pending, DeclarationStatus::Active]);
    expect(fn () => $binder->bindValue($handler, ['active', 'unknown'], $binding))->toThrow(DapterException::class);
    expect(fn () => $binder->bindValue($handler, ['active', null], $binding))->toThrow(DapterException::class);
    expect(fn () => $binder->bindValue($handler, null, $binding))->toThrow(DapterException::class);
    expect(DeclarationFilter::$owners)->toBe(0);
});

it('keeps mixed handlers on wire values unless an explicit enum binding is declared', function (): void {
    $binder = new TrustedHandlerBinder;
    $handler = $binder->describe(DeclarationFilter::class);
    expect($binder->bindValue($handler, 'Ready'))->toBe('Ready')->and($binder->bindValue($handler, null))->toBeNull();
    expect($binder->bindValue($handler, 'Ready', new HandlerOptions(new EnumBinding(DeclarationFlag::class))))->toBe(DeclarationFlag::Ready);
    expect(TrustedHandlerBinder::domain(DeclarationFlag::class))->toBe(['Ready', 'Done']);
    expect(fn () => TrustedHandlerBinder::domain([DeclarationStatus::Active, 'active']))->toThrow(DapterException::class);
});

it('describes invokable classes without construction and builds a fresh owner for each accepted invocation', function (): void {
    DeclarationFilter::$owners = 0;
    $binder = new TrustedHandlerBinder;
    $handler = $binder->describe(DeclarationFilter::class);
    $binder->signature($handler, Collection::class, 2);
    expect(DeclarationFilter::$owners)->toBe(0);
    $binder->invoke($handler, [collect(), 1]);
    $binder->invoke($handler, [collect(), 2]);
    expect(DeclarationFilter::$owners)->toBe(2);
    expect(fn () => $binder->signature($handler, Builder::class, 2))->toThrow(DapterException::class);
    expect(fn () => $binder->signature($handler, Collection::class, 1))->toThrow(DapterException::class);
});

it('accepts declared source unions while rejecting implicit service operands', function (): void {
    $binder = new TrustedHandlerBinder;
    $handler = $binder->describe(static fn (Collection|Builder $rows): Collection|Builder => $rows);
    $binder->signature($handler, Collection::class, 1);
    $binder->signature($handler, Builder::class, 1);
    expect(fn () => $binder->signature($binder->describe(static fn (Collection $rows, stdClass $service): Collection => $rows), Collection::class, 1))->toThrow(DapterException::class);
});

it('rejects a later invalid finite member before mandatory source or any selected handler construction', function (): void {
    $source = BindingLifecycleSource::class;
    resolve(SourceRegistry::class)->register('BindingLifecycle', $source);
    $source::$sources = 0;
    DeclarationFilter::$owners = 0;
    $state = ['version' => 2, 'fields' => (object) [], 'global' => ['filters' => ['first' => ['value' => 1], 'status' => ['value' => ['active', 'unknown']]]], 'searchMatch' => 'all', 'order' => ['kind' => 'default'], 'paging' => ['mode' => 'offset', 'current' => 1, 'per_page' => 10]];
    $this->postJson(route('dapter.data', ['source' => 'BindingLifecycle']), ['version' => 2, 'state' => $state])->assertUnprocessable();
    expect($source::$sources)->toBe(0)->and(DeclarationFilter::$owners)->toBe(0);
    $state['global']['filters']['status']['value'] = ['active', 'pending'];
    $this->postJson(route('dapter.data', ['source' => 'BindingLifecycle']), ['version' => 2, 'state' => $state])->assertOk();
    expect($source::$sources)->toBe(1)->and(DeclarationFilter::$owners)->toBe(2);
});

it('permits null only through an explicit nullable parameter and preserves supported enum subsets', function (): void {
    $binder = new TrustedHandlerBinder;
    $nullable = $binder->describe(static fn (Collection $rows, ?DeclarationStatus $status): Collection => $rows);
    expect($binder->bindValue($nullable, null))->toBeNull()->and($binder->bindValue($nullable, 'pending'))->toBe(DeclarationStatus::Pending);
    $multiple = $binder->describe(static fn (Collection $rows, ?array $values): Collection => $rows);
    expect($binder->bindValue($multiple, ['active', null], new HandlerOptions(new EnumBinding(DeclarationStatus::class, elements: true))))->toBe([DeclarationStatus::Active, null]);
    expect(TrustedHandlerBinder::domain([DeclarationStatus::Pending, null]))->toBe(['pending', null]);
    expect(fn () => TrustedHandlerBinder::domain([1, 1.0]))->toThrow(DapterException::class);
});

it('uses an explicit enum binding to disambiguate compatible union parameters', function (): void {
    $binder = new TrustedHandlerBinder;
    $binding = new HandlerOptions(new EnumBinding(DeclarationStatus::class));
    $handler = $binder->describe(static fn (Collection $rows, DeclarationStatus|OtherDeclarationStatus|null $status): Collection => $rows);
    expect($binder->bindValue($handler, 'active', $binding))->toBe(DeclarationStatus::Active)
        ->and($binder->bindValue($handler, null, $binding))->toBeNull();
    expect(fn () => $binder->bindValue($handler, 'unknown', $binding))->toThrow(DapterException::class);
    expect(fn () => $binder->bindValue($handler, 'active'))->toThrow(DapterException::class);
    expect(fn () => $binder->bindValue($handler, 'Ready', new HandlerOptions(new EnumBinding(DeclarationFlag::class))))->toThrow(DapterException::class);
    $interface = $binder->describe(static fn (Collection $rows, UnitEnum|string $status): Collection => $rows);
    expect($binder->bindValue($interface, 'active', $binding))->toBe(DeclarationStatus::Active);
    $elements = $binder->describe(static fn (Collection $rows, array|stdClass $statuses): Collection => $rows);
    expect($binder->bindValue($elements, ['active', 'pending'], new HandlerOptions(new EnumBinding(DeclarationStatus::class, elements: true))))->toBe([DeclarationStatus::Active, DeclarationStatus::Pending]);
});
