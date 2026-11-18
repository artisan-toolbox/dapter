<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\EnumBinding;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\HandlerOptions;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Options\SearchTarget;
use ArtisanToolbox\Dapter\StoreOptions;
use ArtisanToolbox\Dapter\Support\TrustedHandlerBinder;
use ArtisanToolbox\Dapter\Tests\Fixtures\AdvancedChildSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\AdvancedParentSource;
use ArtisanToolbox\Dapter\Tests\Fixtures\DeclarationEnumFilter;
use ArtisanToolbox\Dapter\Tests\Fixtures\DeclarationFilter;
use ArtisanToolbox\Dapter\Tests\Fixtures\DeclarationFlag;
use ArtisanToolbox\Dapter\Tests\Fixtures\DeclarationStatus;
use ArtisanToolbox\Dapter\Tests\Fixtures\OtherDeclarationStatus;
use Illuminate\Support\Collection;

require_once __DIR__.'/../Fixtures/AdvancedDeclarationSources.php';
function advancedOptionsSource(StoreOptions $options): CollectionDataSource
{
    return new class($options) extends CollectionDataSource implements WithOptions
    {
        public function __construct(private readonly StoreOptions $configuration) {}

        public function source(): array
        {
            return [['id' => 1, 'name' => 'Lin']];
        }

        public function options(): StoreOptions
        {
            return $this->configuration;
        }
    };
}
it('composes config parent child and fluent keyed layers without constructing a handler', function (): void {
    DeclarationFilter::$owners = 0;
    config()->set('dapter.declarations', ['global' => ['filters' => ['configured' => ['values' => [1, 'one', false, null], 'apply' => DeclarationFilter::class]]]]);
    $options = (new OptionsResolver)->resolve(new AdvancedChildSource);
    expect(array_keys($options->global->filters))->toBe(['configured', 'flag'])->and($options->global->filters['configured']->values)->toBe([1, 'one', false, null])->and($options->global->filters['flag']->values)->toBe(['Ready'])->and($options->searchMatch)->toBe(SearchMatch::Any)->and($options->multiOrder)->toBeTrue()->and($options->maxOrder)->toBe(3)->and(DeclarationFilter::$owners)->toBe(0);
    $binder = new TrustedHandlerBinder;
    expect($binder->invoke($binder->describe($options->global->filters['flag']->apply), [collect(), DeclarationFlag::Ready]))->toBeInstanceOf(Collection::class);
});
it('captures primitive configuration at construction and never reseeds makeWithoutDefaults', function (): void {
    config()->set('dapter.declarations', ['global' => ['filters' => ['configured' => ['values' => [1], 'apply' => DeclarationFilter::class]]]]);
    $captured = StoreOptions::make()->publicAccess();
    config()->set('dapter.declarations', ['global' => ['filters' => ['later' => ['values' => [2], 'apply' => DeclarationFilter::class]]]]);
    $resolver = new OptionsResolver;
    expect(array_keys($resolver->resolve(advancedOptionsSource($captured))->global->filters))->toBe(['configured']);
    $bare = StoreOptions::makeWithoutDefaults()->publicAccess()->pagingMode(PagingMode::Offset)->pageSize(10)->guard('web');
    expect($resolver->resolve(advancedOptionsSource($bare))->global->filters)->toBe([]);
});
it('expands enum domains preserves subsets and binds exact cases without constructing handlers', function (): void {
    $declaration = StoreOptions::make()->publicAccess()->filterableField('status', 'status', [DeclarationStatus::Pending])->globalFilterable('status', [DeclarationStatus::Active], DeclarationEnumFilter::class);
    $options = (new OptionsResolver)->resolve(advancedOptionsSource($declaration));
    expect($options->fields['status']->filters['status']->values)->toBe(['pending'])->and($options->global->filters['status']->values)->toBe(['active']);
    $binder = new TrustedHandlerBinder;
    $handler = $binder->describe(DeclarationEnumFilter::class);
    expect($binder->bindValue($handler, 'active'))->toBe(DeclarationStatus::Active);
    expect(fn () => $binder->bindValue($handler, 'pending', new HandlerOptions(new EnumBinding(OtherDeclarationStatus::class))))->toThrow(DapterException::class);
    expect(fn () => StoreOptions::make()->globalFilterable('bad', [OtherDeclarationStatus::Active], DeclarationEnumFilter::class))->toThrow(DapterException::class);
});
it('keeps literal field outputs opt in and rejects duplicate same layer keyed entries', function (): void {
    $options = (new OptionsResolver)->resolve(advancedOptionsSource(StoreOptions::make()->publicAccess()->searchableField('label', target: 'name')->orderableField('alias', target: 'name', options: new FieldOptions('display.name'))));
    expect($options->fields['label']->output)->toBeNull()->and($options->fields['alias']->output)->toBe('display.name');
    expect(fn () => StoreOptions::make()->globalFilterable('status', [1], DeclarationFilter::class)->withoutGlobalFilter('status'))->toThrow(DapterException::class);
});
it('rejects objects unknown keys and incompatible automatic field domains in configuration', function (array $config): void {
    config()->set('dapter.declarations', $config);
    expect(fn () => (new OptionsResolver)->resolve(advancedOptionsSource(StoreOptions::make()->publicAccess())))->toThrow(DapterException::class);
})->with([
    [['unknown' => true]],
    [['global' => ['search' => ['name' => new SearchTarget('name')]]]],
    [['fields' => ['status' => ['filters' => ['status' => ['values' => [1, 'one']]]]]]],
    [['global' => ['filters' => ['state' => ['values' => [1], 'apply' => DeclarationFilter::class, 'unknown' => true]]]]],
]);
it('removes global declarations with fluent tombstones while keeping inherited search and order', function (): void {
    $source = new class extends AdvancedParentSource
    {
        public function options(): StoreOptions
        {
            return parent::options()->withoutGlobalSearch()->withoutGlobalFilter('flag')->withoutGlobalOrder('name');
        }
    };
    $options = (new OptionsResolver)->resolve($source);
    expect($options->global->search)->toBeNull()->and(array_keys($options->global->filters))->toBe(['status'])->and($options->global->orders)->toBe([]);
});

it('resolves a finite enum subset with an explicitly bound union handler', function (): void {
    $options = StoreOptions::make()->publicAccess()->globalFilterable('status', [DeclarationStatus::Active],
        static fn (Collection $rows, DeclarationStatus|OtherDeclarationStatus $status): Collection => $rows,
        options: new HandlerOptions(new EnumBinding(DeclarationStatus::class)));
    $resolved = (new OptionsResolver)->resolve(advancedOptionsSource($options));
    expect($resolved->global->filters['status']->values)->toBe(['active']);
});
