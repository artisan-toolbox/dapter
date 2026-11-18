<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers\Comparisons {
    function class_exists(string $class): bool
    {
        return false;
    }
}

namespace {
    require __DIR__.'/../../vendor/autoload.php';

    use ArtisanToolbox\Dapter\Drivers\Comparisons\ComparisonPolicy;
    use ArtisanToolbox\Dapter\Drivers\OrderTerm;
    use ArtisanToolbox\Dapter\Drivers\ReadSelection;
    use ArtisanToolbox\Dapter\Enums\AccessMode;
    use ArtisanToolbox\Dapter\Enums\PagingMode;
    use ArtisanToolbox\Dapter\Enums\SortDirection;
    use ArtisanToolbox\Dapter\Enums\SortType;
    use ArtisanToolbox\Dapter\Enums\ValueType;
    use ArtisanToolbox\Dapter\Http\DapterException;
    use ArtisanToolbox\Dapter\Options\FieldDefinition;
    use ArtisanToolbox\Dapter\Options\FieldOptions;
    use ArtisanToolbox\Dapter\Options\FixedOrderTerm;
    use ArtisanToolbox\Dapter\Options\LocaleOptions;
    use ArtisanToolbox\Dapter\Options\OrderDefinition;
    use ArtisanToolbox\Dapter\Options\QueryOptions;
    use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;

    $fields = ['name' => new FieldDefinition('name', order: new OrderDefinition([SortType::Native, SortType::Locale], [SortDirection::Asc], null, ValueType::String, new FieldOptions(locale: new LocaleOptions('sv'))))];
    $options = new ResolvedStoreOptions(PagingMode::Offset, 5, [5], 'web', AccessMode::Public, null, $fields);
    $result = ComparisonPolicy::collection()->resolve($options, new ReadSelection);
    $outcomes = ['optional' => array_map(static fn (SortType $sort): string => $sort->value, $result->options->fields['name']->order->sortTypes)];

    try {
        ComparisonPolicy::collection()->resolve($options, new ReadSelection(order: [new OrderTerm('name', 'name', SortDirection::Asc, SortType::Locale, ValueType::String)]));
    } catch (DapterException $exception) {
        $outcomes['selected'] = [$exception->status, $exception->errorCode];
    }
    $fixed = new ResolvedStoreOptions(PagingMode::Offset, 5, [5], 'web', AccessMode::Public, null, $fields, query: new QueryOptions(fixedOrder: [new FixedOrderTerm('name', sortType: SortType::Locale)]));

    try {
        ComparisonPolicy::collection()->resolve($fixed, new ReadSelection);
    } catch (DapterException $exception) {
        $outcomes['fixed'] = [$exception->status, $exception->errorCode];
    }
    echo json_encode($outcomes, JSON_THROW_ON_ERROR);
}
