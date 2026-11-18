<?php

declare(strict_types=1);

use App\DataSources\PagingArray;
use App\DataSources\PagingFixture;
use ArtisanToolbox\Dapter\Attributes\SearchableField;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Options\VectorOptions;
use ArtisanToolbox\Dapter\Support\VectorValue;

require_once __DIR__.'/../Fixtures/PagingHarness.php';

it('omits vector search on collection and SQLite and rejects forged selections', function (string $backend): void {
    $source = pagingPrepare($backend, PagingMode::Offset);
    PagingFixture::$options = PagingFixture::$options->searchableField('embedding', [MatchType::Vector], target: 'name', options: new FieldOptions(vector: new VectorOptions(3, 1.0)));
    $this->postJson('/dapter/data/'.$source, ['version' => 2])->assertOk()->assertJsonPath('capabilities.fields.embedding.search', null);
    $intent = pagingIntent(current: 1);
    $intent['state']['paging']['mode'] = 'offset';
    $intent['state']['fields'] = (object) ['embedding' => ['search' => ['value' => [0, 0, 0], 'matchType' => 'vector']]];
    $this->postJson('/dapter/data/'.$source, $intent)->assertStatus(422)->assertJsonPath('error.kind', 'unsupported_operation')->assertJsonMissingPath('data');
})->with(['array', 'sqlite']);

it('rejects malformed prepared vector input before querying an unsupported backend', function (mixed $value): void {
    pagingPrepare('sqlite', PagingMode::Offset);
    PagingFixture::$options = PagingFixture::$options->searchableField('embedding', [MatchType::Vector], target: 'name', options: new FieldOptions(vector: new VectorOptions(3, 1.0)));
    $intent = pagingIntent(current: 1);
    $intent['state']['paging']['mode'] = 'offset';
    $intent['state']['fields'] = (object) ['embedding' => ['search' => ['value' => $value, 'matchType' => 'vector']]];
    $this->postJson('/dapter/data/PagingSql', $intent)->assertStatus(422)->assertJsonPath('error.kind', 'validation')->assertJsonMissingPath('data');
})->with([
    'text' => ['cats'], 'empty text' => [''], 'null vector' => [null], 'empty vector' => [[]],
    'wrong dimensions' => [[0, 0]], 'oversized' => [[0, 0, 0, 0]], 'sparse' => [[0 => 0, 2 => 0, 3 => 0]],
    'null coordinate' => [[0, null, 0]], 'boolean' => [[0, false, 0]], 'numeric string' => [[0, '1', 0]],
    'nested' => [[0, [0], 0]], 'object' => [(object) ['x' => 0, 'y' => 0, 'z' => 0]],
    'out of bounds' => [[1000001, 0, 0]],
]);

it('normalizes prepared coordinates to float32 and rejects non-finite PHP coordinates', function (): void {
    expect(VectorValue::normalize([0.1, -0.0, 1000000], new VectorOptions(3, 1.0)))->toBe([0.10000000149011612, -0.0, 1000000.0]);
    foreach ([NAN, INF, -INF] as $value) {
        expect(fn () => VectorValue::normalize([$value, 0, 0], new VectorOptions(3, 1.0)))->toThrow(DapterException::class);
    }
});

it('rejects invalid vector dimensions and thresholds', function (int $dimensions, float $distance): void {
    expect(fn () => new VectorOptions($dimensions, $distance))->toThrow(DapterException::class);
})->with([[0, 1.0], [16001, 1.0], [3, -1.0], [3, NAN], [3, INF], [3, -INF], [3, 9007199254740992.0]]);

it('admits equivalent attribute and primitive configuration vector declarations', function (): void {
    $source = new #[SearchableField('embedding', [MatchType::Vector], options: new FieldOptions(vector: new VectorOptions(3, 2.0)))] class extends CollectionDataSource
    {
        public function source(): array
        {
            return [];
        }
    };
    $attribute = (new OptionsResolver)->resolve($source)->fields['embedding']->search;
    config()->set('dapter.declarations', ['fields' => ['embedding' => ['search' => ['matchTypes' => ['vector'], 'options' => ['vector' => ['dimensions' => 3, 'maxDistance' => 2.0]]]]]]);
    pagingPrepare('array');
    $configuration = (new OptionsResolver)->resolve(new PagingArray)->fields['embedding']->search;
    expect($attribute->options->vector)->toEqual($configuration->options->vector);
});

it('rejects vector declarations that mix scalar and vector contracts', function (array $declaration): void {
    config()->set('dapter.declarations', $declaration);
    pagingPrepare('array');
    expect(fn () => (new OptionsResolver)->resolve(new PagingArray))->toThrow(DapterException::class);
})->with([
    [['fields' => ['embedding' => ['search' => ['matchTypes' => ['vector']]]]]],
    [['fields' => ['embedding' => ['search' => ['matchTypes' => ['contains'], 'options' => ['vector' => ['dimensions' => 3, 'maxDistance' => 1]]]]]]],
    [['fields' => ['embedding' => ['search' => ['matchTypes' => ['vector', 'contains'], 'options' => ['vector' => ['dimensions' => 3, 'maxDistance' => 1]]]]]]],
    [['fields' => ['embedding' => ['search' => ['matchTypes' => ['vector'], 'valueType' => 'number', 'options' => ['vector' => ['dimensions' => 3, 'maxDistance' => 1]]]]]]],
    [['fields' => ['embedding' => ['order' => ['options' => ['vector' => ['dimensions' => 3, 'maxDistance' => 1]]]]]]],
    [['fields' => ['embedding' => ['filters' => ['sample' => ['values' => [1], 'options' => ['vector' => ['dimensions' => 3, 'maxDistance' => 1]]]]]]]],
    [['global' => ['search' => ['embedding' => ['target' => 'embedding', 'matchType' => 'vector']]]]],
    [['fields' => ['embedding' => ['search' => ['matchTypes' => ['vector'], 'options' => ['vector' => ['dimensions' => '3', 'maxDistance' => 1]]]]]]],
    [['fields' => ['embedding' => ['search' => ['matchTypes' => ['vector'], 'options' => ['vector' => ['dimensions' => 3, 'maxDistance' => '1']]]]]]],
    [['fields' => ['embedding' => ['search' => ['matchTypes' => ['vector'], 'options' => ['vector' => ['dimensions' => 3, 'maxDistance' => 1, 'metric' => 'cosine']]]]]]],
]);
