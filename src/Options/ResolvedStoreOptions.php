<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\SearchMatch;

final readonly class ResolvedStoreOptions
{
    /**
     * @param  list<int>  $sizes
     * @param  array<string, FieldDefinition>  $fields
     * @param  list<SearchMatch>  $allowedSearchMatches
     * @param  class-string|null  $actionsClass
     * @param  array<string,ActionPolicy>  $actionPolicies
     */
    public function __construct(public PagingMode $mode, public int $size, public array $sizes, public string $guard, public AccessMode $access, public ?GateCheck $authorization, public array $fields = [], public ?string $driver = null, public ?GlobalDefinition $global = null, public bool $multiOrder = false, public int $maxOrder = 1, public SearchMatch $searchMatch = SearchMatch::All, public array $allowedSearchMatches = [SearchMatch::All], public ?QueryOptions $query = null, public ?string $actionsClass = null, public array $actionPolicies = [], public bool $exactCounts = false, public ?string $resource = null, public ?BroadcastOptions $broadcasting = null, public bool $strictLiteralSearch = false, public BrowserStatePolicy $browserState = new BrowserStatePolicy) {}
}
