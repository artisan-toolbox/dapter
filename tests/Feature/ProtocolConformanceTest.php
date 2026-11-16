<?php

declare(strict_types=1);

use App\DataSources\OptionRecords;
use App\DataSources\ProtocolRecords;
use ArtisanToolbox\Dapter\Enums\ReadProtocolVersion;
use ArtisanToolbox\Dapter\StoreOptions;

require_once __DIR__.'/../Fixtures/ListingSources.php';
require_once __DIR__.'/../Fixtures/ProtocolRecords.php';

/** @return stdClass&object{version: int, fixture: object{rows: list<mixed>, per_page: int, per_page_options: list<int>}, requests: list<object{id: string, context: string, request_json: string, status: int, response: mixed, reads: int}>} */
function readProtocolCorpus(): stdClass
{
    $json = file_get_contents(__DIR__.'/../../resources/contracts/read-v1.json');

    throw_if($json === false, RuntimeException::class, 'Missing public read protocol examples.');

    return json_decode($json, flags: JSON_THROW_ON_ERROR);
}

dataset('current read requests', function (): iterable {
    foreach (readProtocolCorpus()->requests as $request) {
        yield $request->id => [$request];
    }
});

/** @param object{id: string, context: string, request_json: string, status: int, response: mixed, reads: int} $case */
it('conforms to the public raw read protocol examples', function (stdClass $case) {
    $corpus = readProtocolCorpus();
    $fixture = $corpus->fixture;
    expect($corpus->version)->toBe(ReadProtocolVersion::V1->value);
    OptionRecords::$settings = StoreOptions::make()->publicAccess($case->context !== 'required')
        ->pageSize($fixture->per_page)->pageSizeOptions($fixture->per_page_options);
    OptionRecords::$reads = 0;
    OptionRecords::$rows = $case->context === 'empty' ? [] : $fixture->rows;
    ProtocolRecords::$failureContext = in_array($case->context, ['server', 'custom422'], true) ? $case->context : null;
    $source = $case->context === 'missing' ? 'MissingProtocolRecords' : 'ProtocolRecords';
    $response = $this->call('POST', '/dapter/data/'.$source,
        server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'],
        content: $case->request_json)->assertStatus($case->status);
    expect(json_decode($response->content(), flags: JSON_THROW_ON_ERROR))->toEqual($case->response)
        ->and(OptionRecords::$reads)->toBe($case->reads);
})->with('current read requests');
