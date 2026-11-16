<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Drivers\CollectionStoreDriver;
use ArtisanToolbox\Dapter\Drivers\DriverCapabilities;
use ArtisanToolbox\Dapter\Drivers\NegotiatedRead;
use ArtisanToolbox\Dapter\Drivers\RawPage;
use ArtisanToolbox\Dapter\Drivers\ValidatedReadPlan;
use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use Illuminate\Support\Collection;

it('requires declared offset support before executing a bound driver', function () {
    $driver = new class implements StoreDriver
    {
        public int $profiles = 0;

        public int $executions = 0;

        public function capabilities(): DriverCapabilities
        {
            $this->profiles++;

            return new DriverCapabilities(offset: false);
        }

        public function execute(ValidatedReadPlan $plan): RawPage
        {
            $this->executions++;

            return new RawPage([], new OffsetRequest(1, 5), 1, 0);
        }
    };
    $options = new ResolvedStoreOptions(PagingMode::Offset, 10, [5, 10], 'web', AccessMode::Public, null);

    try {
        NegotiatedRead::fromDriver($driver, $options, new OffsetRequest(1, 5));
        test()->fail('A source without required paging support must be rejected.');
    } catch (DapterException $exception) {
        expect($exception->kind)->toBe(ErrorKind::Server)->and($exception->errorCode)->toBe('invalid_source')->and($exception->status)->toBe(500);
    }

    expect($driver->profiles)->toBe(1)->and($driver->executions)->toBe(0);
});

it('executes and advertises one accepted profile while retaining the selected request and declaration', function () {
    $driver = new class implements StoreDriver
    {
        public int $profiles = 0;

        public ?ValidatedReadPlan $executed = null;

        public function capabilities(): DriverCapabilities
        {
            $this->profiles++;

            return new DriverCapabilities(offset: $this->profiles === 1);
        }

        public function execute(ValidatedReadPlan $plan): RawPage
        {
            $this->executed = $plan;

            return new RawPage([['name' => 'Notebook']], new OffsetRequest(2, $plan->paging->perPage), 2, 6);
        }
    };
    $options = new ResolvedStoreOptions(PagingMode::Offset, 10, [5, 10], 'web', AccessMode::Public, null);
    $requested = new OffsetRequest(9007199254740991, 5);
    $read = NegotiatedRead::fromDriver($driver, $options, $requested);
    $capabilities = $read->wireCapabilities();
    $page = $read->execute();

    expect($driver->profiles)->toBe(1)->and($driver->executed?->options)->toBe($options)->and($driver->executed?->paging)->toBe($requested)
        ->and($requested->current)->toBe(9007199254740991)->and($page->paging->current)->toBe(2)
        ->and($options->size)->toBe(10)->and($options->sizes)->toBe([5, 10])
        ->and($capabilities['paging'])->toBe(['mode' => 'offset', 'per_page' => 10, 'per_page_options' => [5, 10]])
        ->and(get_object_vars($capabilities['fields']))->toBe([])
        ->and($read->wireCapabilities())->toEqual($capabilities)->and($driver->profiles)->toBe(1);
});

it('declares collection offset support explicitly and checks every paging mode', function () {
    $profile = (new CollectionStoreDriver(new Collection))->capabilities();

    foreach (PagingMode::cases() as $mode) {
        expect($profile->supports($mode))->toBeTrue()->and((new DriverCapabilities(offset: false))->supports($mode))->toBeFalse();
    }
});
