<?php

declare(strict_types=1);

use App\DataSources\OptionRecords;
use ArtisanToolbox\Dapter\Attributes\GlobalSearch;
use ArtisanToolbox\Dapter\Attributes\OrderableField;
use ArtisanToolbox\Dapter\Attributes\SearchableField;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\FullTextOptions;
use ArtisanToolbox\Dapter\Options\LocaleOptions;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Options\SearchTarget;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Fixtures/ListingSources.php';

beforeEach(function (): void {
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    OptionRecords::$reads = 0;
    OptionRecords::$rows = [];
});

/** @param array<string,mixed> $fields
 * @param array<string,mixed>|null $order
 * @return array<string,mixed> */
function comparisonRequest(array $fields = [], ?array $order = null): array
{
    return ['version' => 2, 'state' => ['version' => 2, 'fields' => (object) $fields, 'global' => (object) [], 'searchMatch' => 'all', 'order' => $order ?? ['kind' => 'default'], 'paging' => ['mode' => 'offset', 'current' => 1, 'per_page' => 5]]];
}

it('orders explicit ICU strings with stable normalized ties and nulls', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->orderableField('name', [SortType::Native, SortType::Locale], options: new FieldOptions(locale: new LocaleOptions('sv')));
    OptionRecords::$rows = [['id' => 1, 'name' => 'ä'], ['id' => 2, 'name' => 'z'], ['id' => 3, 'name' => 'a'], ['id' => 4, 'name' => null], ['id' => 5, 'name' => 'a']];
    $order = ['kind' => 'fields', 'terms' => [['field' => 'name', 'direction' => 'asc', 'sortType' => 'locale']]];
    $response = $this->postJson('/dapter/data/OptionRecords', comparisonRequest(order: $order))->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe([4, 3, 5, 2, 1]);
    $response->assertJsonPath('capabilities.fields.name.order.locale.strength', 'tertiary')->assertJsonPath('capabilities.fields.name.order.locale.normalization', true)->assertJsonPath('capabilities.fields.name.order.locale.numeric', false);
});

it('accepts ICU parent inheritance and keeps numeric strings textual', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->orderableField('name', [SortType::Locale], options: new FieldOptions(locale: new LocaleOptions('pt-BR')));
    OptionRecords::$rows = [['id' => 1, 'name' => 'item2'], ['id' => 2, 'name' => 'item10'], ['id' => 3, 'name' => 'é'], ['id' => 4, 'name' => "e\u{0301}"]];
    $response = $this->postJson('/dapter/data/OptionRecords', comparisonRequest(order: ['kind' => 'fields', 'terms' => [['field' => 'name', 'direction' => 'asc', 'sortType' => 'locale']]]))->assertOk();
    expect(array_column($response->json('data'), 'id'))->toBe([3, 4, 2, 1]);
    $response->assertJsonPath('capabilities.fields.name.order.locale.locale', 'pt_BR')->assertJsonPath('capabilities.fields.name.order.locale.validLocale', 'pt')->assertJsonPath('capabilities.fields.name.order.locale.actualLocale', 'root');
});

it('rejects invented locales and ICU keyword or extension settings', function (string $locale): void {
    OptionRecords::$settings = OptionRecords::$settings->orderableField('name', [SortType::Locale], options: new FieldOptions(locale: new LocaleOptions($locale)));
    OptionRecords::$rows = [['name' => 'a']];
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
})->with(['xx', 'pt-ZZ', 'root', 'en@collation=phonebook', 'en-US-u-kn-true']);

it('rejects misplaced or missing explicit comparison settings before source work', function (StoreOptions $settings): void {
    OptionRecords::$settings = $settings->publicAccess();
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
    expect(OptionRecords::$reads)->toBe(0);
})->with([
    fn () => StoreOptions::make()->orderableField('name', [SortType::Locale]),
    fn () => StoreOptions::make()->orderableField('name', options: new FieldOptions(locale: new LocaleOptions('sv'))),
    fn () => StoreOptions::make()->searchableField('name', [MatchType::FullText]),
    fn () => StoreOptions::make()->searchableField('name', options: new FieldOptions(fullText: new FullTextOptions('pg_catalog.english'))),
    fn () => StoreOptions::make()->searchableField('name', options: new FieldOptions(locale: new LocaleOptions('sv'))),
    fn () => StoreOptions::make()->orderableField('name', options: new FieldOptions(fullText: new FullTextOptions('pg_catalog.english'))),
    fn () => StoreOptions::make()->searchableField('name', [MatchType::FullText], options: new FieldOptions(fullText: new FullTextOptions('english'))),
    fn () => StoreOptions::make()->globalSearchable(['body' => new SearchTarget('name', fullText: new FullTextOptions('pg_catalog.english'))]),
]);

it('omits optional unsupported collection full text and rejects selected full text', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->searchableField('name', [MatchType::Contains, MatchType::FullText], options: new FieldOptions(fullText: new FullTextOptions('pg_catalog.english')));
    OptionRecords::$rows = [['name' => 'cats']];
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertOk()->assertJsonPath('capabilities.fields.name.search.matchTypes', ['contains'])->assertJsonMissingPath('capabilities.fields.name.search.fullText');
    $this->postJson('/dapter/data/OptionRecords', comparisonRequest(['name' => ['search' => ['value' => 'cats', 'matchType' => 'full_text']]]))->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation');
});

it('fails required unavailable global full text', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->globalSearchable(['body' => new SearchTarget('name', matchType: MatchType::FullText, fullText: new FullTextOptions('pg_catalog.english'))]);
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
});

it('rejects mixed valid filters and forged regex before source creation', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->filterableField('id', 'allowed', [1], valueType: ValueType::Integer)->searchableField('name');
    $this->postJson('/dapter/data/OptionRecords', comparisonRequest(['id' => ['filters' => ['allowed' => ['value' => 1]]], 'name' => ['search' => ['value' => '(a+)+$', 'matchType' => 'regex']]]))->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation');
    expect(OptionRecords::$reads)->toBe(0);
});

it('validates active full text bytes before source creation', function (string $value): void {
    OptionRecords::$settings = OptionRecords::$settings->searchableField('name', [MatchType::FullText], options: new FieldOptions(fullText: new FullTextOptions('pg_catalog.english')));
    $this->postJson('/dapter/data/OptionRecords', comparisonRequest(['name' => ['search' => ['value' => $value, 'matchType' => 'full_text']]]))->assertStatus(422)->assertJsonPath('error.kind', 'validation');
    expect(OptionRecords::$reads)->toBe(0);
})->with([str_repeat('a', 513), "cats\0dogs", str_repeat('é', 257)]);

it('rejects malformed UTF8 in singleton locale data before a comparator can be skipped', function (): void {
    OptionRecords::$settings = OptionRecords::$settings->orderableField('name', [SortType::Locale], options: new FieldOptions(locale: new LocaleOptions('sv')));
    OptionRecords::$rows = [['name' => "\xff"]];
    $applied = 0;
    OptionRecords::$settings = OptionRecords::$settings->globalFilterable('visible', [1], static function (Collection $rows, int $value) use (&$applied): Collection {
        $applied++;

        return $rows;
    });
    $intent = comparisonRequest(order: ['kind' => 'fields', 'terms' => [['field' => 'name', 'direction' => 'asc', 'sortType' => 'locale']]]);
    $intent['state']['global'] = (object) ['filters' => ['visible' => ['value' => 1]]];
    $this->postJson('/dapter/data/OptionRecords', $intent)->assertStatus(500);
    expect($applied)->toBe(0);
});

it('omits unavailable intl while rejecting selected locale and required locale prefixes', function (): void {
    $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/ComparisonWithoutIntl.php']);
    $process->mustRun();
    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe(['optional' => ['native'], 'selected' => [422, 'unsupported_operation'], 'fixed' => [500, 'invalid_source']]);
});

it('parses locale and full text configuration into the same typed declarations as fluent APIs', function (): void {
    config()->set('dapter.declarations', ['fields' => [
        'name' => ['order' => ['sortTypes' => ['native', 'locale'], 'valueType' => 'string', 'options' => ['locale' => ['locale' => 'sv']]]],
        'body' => ['search' => ['matchTypes' => ['contains', 'full_text'], 'options' => ['fullText' => ['configuration' => 'pg_catalog.english']]]],
    ], 'global' => ['search' => ['article' => ['target' => 'body', 'matchType' => 'full_text', 'fullText' => ['configuration' => 'pg_catalog.english']]]]]);
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    $options = (new OptionsResolver)->resolve(new OptionRecords);
    expect($options->fields['name']->order->options->locale)->toEqual(new LocaleOptions('sv'))
        ->and($options->fields['body']->search->options->fullText)->toEqual(new FullTextOptions('pg_catalog.english'))
        ->and($options->global->search['article']->fullText)->toEqual(new FullTextOptions('pg_catalog.english'));
});

it('rejects unknown nested comparison configuration and misplaced settings', function (array $configuration): void {
    config()->set('dapter.declarations', $configuration);
    OptionRecords::$settings = StoreOptions::make()->publicAccess();
    $this->postJson('/dapter/data/OptionRecords', ['version' => 2])->assertStatus(500);
    expect(OptionRecords::$reads)->toBe(0);
})->with([
    [['fields' => ['name' => ['order' => ['sortTypes' => ['locale'], 'options' => ['locale' => ['locale' => 'sv', 'numeric' => true]]]]]]],
    [['fields' => ['body' => ['search' => ['matchTypes' => ['full_text'], 'options' => ['fullText' => ['configuration' => 'pg_catalog.english', 'rank' => true]]]]]]],
    [['fields' => ['name' => ['order' => ['sortTypes' => ['locale'], 'options' => ['locale' => []]]]]]],
    [['global' => ['search' => ['body' => ['target' => 'body', 'matchType' => 'full_text', 'fullText' => ['configuration' => 'pg_catalog.english', 'parser' => 'query']]]]]],
]);

it('shares comparison declarations with field and global search attributes', function (): void {
    $source = new
        #[OrderableField('name', [SortType::Locale], options: new FieldOptions(locale: new LocaleOptions('sv')))]
        #[SearchableField('body', [MatchType::FullText], options: new FieldOptions(fullText: new FullTextOptions('pg_catalog.english')))]
        #[GlobalSearch(['article' => new SearchTarget('body', matchType: MatchType::FullText, fullText: new FullTextOptions('pg_catalog.english'))])]
        class extends OptionRecords {};
    $options = (new OptionsResolver)->resolve($source);
    expect($options->fields['name']->order->options->locale)->toEqual(new LocaleOptions('sv'))
        ->and($options->fields['body']->search->options->fullText)->toEqual(new FullTextOptions('pg_catalog.english'))
        ->and($options->global->search['article']->fullText)->toEqual(new FullTextOptions('pg_catalog.english'));
});

it('keeps required unavailable declarations configuration failures despite selected unavailable modes', function (): void {
    OptionRecords::$settings = OptionRecords::$settings
        ->searchableField('body', [MatchType::Contains, MatchType::FullText], options: new FieldOptions(fullText: new FullTextOptions('pg_catalog.english')))
        ->globalSearchable(['article' => new SearchTarget('body', matchType: MatchType::FullText, fullText: new FullTextOptions('pg_catalog.english'))]);
    OptionRecords::$rows = [['body' => 'cats']];
    $this->postJson('/dapter/data/OptionRecords', comparisonRequest(['body' => ['search' => ['value' => 'cats', 'matchType' => 'full_text']]]))->assertStatus(500)->assertJsonPath('error.code', 'invalid_source');
});
