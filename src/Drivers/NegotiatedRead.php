<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\OffsetRequest;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use stdClass;

/** @internal */
final readonly class NegotiatedRead
{
    private function __construct(
        private StoreDriver $driver,
        private ValidatedReadPlan $plan,
    ) {}

    public static function fromDriver(StoreDriver $driver, ResolvedStoreOptions $options, OffsetRequest $paging): self
    {
        $capabilities = $driver->capabilities();

        if (! $capabilities->supports($options->mode)) {
            throw DapterException::configuration();
        }

        return new self($driver, new ValidatedReadPlan($options, $paging));
    }

    public function execute(): RawPage
    {
        return $this->driver->execute($this->plan);
    }

    /** @return array{fields: stdClass, global: array<string, mixed>, paging: array{mode: string, per_page: int, per_page_options: list<int>}} */
    public function wireCapabilities(): array
    {
        return [
            'fields' => (object) [],
            'global' => ['multi_order' => false, 'multi_order_limit' => 1, 'searchMatch' => ['default' => SearchMatch::All->value, 'allowed' => [SearchMatch::All->value]], 'filters' => null, 'search' => null, 'order' => null],
            'paging' => ['mode' => $this->plan->options->mode->value, 'per_page' => $this->plan->options->size, 'per_page_options' => $this->plan->options->sizes],
        ];
    }
}
