<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter;

use ArtisanToolbox\Dapter\Broadcasting\PrivateChannel;
use ArtisanToolbox\Dapter\Broadcasting\PublicChannel;
use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\EmptySelectionPolicy;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\ActionPolicy;
use ArtisanToolbox\Dapter\Options\BroadcastOptions;
use ArtisanToolbox\Dapter\Options\FieldOperation;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\FieldSetting;
use ArtisanToolbox\Dapter\Options\FilterDefinition;
use ArtisanToolbox\Dapter\Options\GateCheck;
use ArtisanToolbox\Dapter\Options\GlobalFilterDefinition;
use ArtisanToolbox\Dapter\Options\GlobalOrderDefinition;
use ArtisanToolbox\Dapter\Options\HandlerOptions;
use ArtisanToolbox\Dapter\Options\OrderDefinition;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use ArtisanToolbox\Dapter\Options\RecordTarget;
use ArtisanToolbox\Dapter\Options\SearchDefinition;
use ArtisanToolbox\Dapter\Options\SearchTarget;
use ArtisanToolbox\Dapter\Support\TrustedHandlerBinder;
use Closure;
use Illuminate\Http\Resources\Json\JsonResource;
use UnitEnum;

final class StoreOptions
{
    /** @var list<FieldSetting> */
    private array $fields = [];

    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $patch
     */
    private function __construct(private readonly array $baseline, private array $patch = []) {}

    public static function make(): self
    {
        return new self([
            'paging' => config('dapter.paging.mode'),
            'exact_counts' => config('dapter.paging.exact_counts', false),
            'size' => config('dapter.paging.per_page'),
            'sizes' => config('dapter.paging.per_page_options'),
            'guard' => config('dapter.access.guard'),
            'broadcast_context' => config('dapter.broadcasting.context'),
            'declarations' => config('dapter.declarations', []),
        ]);
    }

    public static function makeWithoutDefaults(): self
    {
        return new self([]);
    }

    /** @param class-string<JsonResource> $resourceClass */
    public function useResource(string $resourceClass): self
    {
        return $this->uniqueOption(OptionKey::Resource, $resourceClass);
    }

    public function broadcastUpdatable(?string $identifierField = null, PrivateChannel|PublicChannel|null $channel = null, ?string $revisions = null, ?string $identity = null): self
    {
        return $this->uniqueOption(OptionKey::Broadcasting, new BroadcastOptions($identifierField, $channel, $revisions, $identity));
    }

    private function uniqueOption(OptionKey $key, mixed $value): self
    {
        if (array_key_exists($key->value, $this->patch)) {
            throw DapterException::configuration();
        }

        return $this->with($key, $value);
    }

    public function pagingMode(PagingMode $mode): self
    {
        return $this->with(OptionKey::Paging, $mode);
    }

    public function strictLiteralSearch(bool $enabled = true): self
    {
        return $this->with(OptionKey::StrictLiteralSearch, $enabled);
    }

    public function exactCounts(bool $enabled = true): self
    {
        return $this->with(OptionKey::ExactCounts, $enabled);
    }

    public function pageSize(int $size): self
    {
        return $this->with(OptionKey::Size, $size);
    }

    /** @param list<int> $sizes */
    public function pageSizeOptions(array $sizes): self
    {
        return $this->with(OptionKey::Sizes, $sizes);
    }

    public function guard(string $name): self
    {
        return $this->with(OptionKey::Guard, $name);
    }

    public function publicAccess(bool $enabled = true): self
    {
        return $this->with(OptionKey::Access, $enabled ? AccessMode::Public : AccessMode::Required);
    }

    public function authorize(string $ability, mixed $arguments = []): self
    {
        return $this->with(OptionKey::Authorization, new GateCheck($ability, $arguments));
    }

    /** @param list<MatchType> $matchTypes */
    public function searchableField(string $field, array $matchTypes = [MatchType::Contains], ?string $target = null, ValueType $valueType = ValueType::String, ?FieldOptions $options = null): self
    {
        return $this->withField(new FieldSetting($field, FieldOperation::Search, null, new SearchDefinition($matchTypes, $target, $valueType, $options)));
    }

    /**
     * @param  list<SortType>  $sortTypes
     * @param  list<SortDirection>  $directions
     */
    public function orderableField(string $field, array $sortTypes = [SortType::Native], array $directions = [SortDirection::Asc, SortDirection::Desc], ?string $target = null, ?ValueType $valueType = null, ?FieldOptions $options = null): self
    {
        return $this->withField(new FieldSetting($field, FieldOperation::Order, null, new OrderDefinition($sortTypes, $directions, $target, $valueType, $options)));
    }

    /** @param array<array-key,string|int|float|bool|null|UnitEnum>|string|UnitEnum $values */
    public function filterableField(string $field, string $id, array|string|UnitEnum $values, bool $multiple = false, EmptySelectionPolicy $empty = EmptySelectionPolicy::Clear, ?string $target = null, ?ValueType $valueType = null, ?FieldOptions $options = null): self
    {
        return $this->withField(new FieldSetting($field, FieldOperation::Filter, $id, new FilterDefinition($values, $multiple, $empty, $target, $valueType, $options)));
    }

    public function withoutFieldSearch(string $field): self
    {
        return $this->withField(new FieldSetting($field, FieldOperation::Search, null, null));
    }

    public function withoutFieldOrder(string $field): self
    {
        return $this->withField(new FieldSetting($field, FieldOperation::Order, null, null));
    }

    public function withoutFieldFilter(string $field, string $id): self
    {
        return $this->withField(new FieldSetting($field, FieldOperation::Filter, $id, null));
    }

    /** @param array<string,SearchTarget> $targets */
    public function globalSearchable(array $targets): self
    {
        return $this->advanced('global.search', $targets);
    }

    /** @param array<array-key,mixed>|string|UnitEnum $values */
    public function globalFilterable(string $id, array|string|UnitEnum $values, Closure|string $apply, bool $multiple = false, EmptySelectionPolicy $empty = EmptySelectionPolicy::Clear, ?ValueType $valueType = null, ?HandlerOptions $options = null): self
    {
        return $this->advanced('global.filter.'.$id, new GlobalFilterDefinition((new TrustedHandlerBinder)->filterDomain($values, $apply, $options), $apply, $multiple, $empty, $valueType, $options));
    }

    public function globalOrderable(string $id, string $label, Closure|string $apply, ?HandlerOptions $options = null): self
    {
        return $this->advanced('global.order.'.$id, new GlobalOrderDefinition($label, $apply, $options));
    }

    public function withoutGlobalSearch(): self
    {
        return $this->advanced('global.search', null);
    }

    public function withoutGlobalFilter(string $id): self
    {
        return $this->advanced('global.filter.'.$id, null);
    }

    public function withoutGlobalOrder(string $id): self
    {
        return $this->advanced('global.order.'.$id, null);
    }

    public function multiOrder(bool $enabled = true, int $max = 1): self
    {
        return $this->advanced('multi_order', [$enabled, $max]);
    }

    /** @param list<SearchMatch> $allowed */
    public function searchMatch(SearchMatch $default = SearchMatch::All, array $allowed = [SearchMatch::All]): self
    {
        return $this->advanced('search_match', [$default, $allowed]);
    }

    public function query(QueryOptions $options): self
    {
        return $this->advanced('query', $options);
    }

    /** @param class-string $actionsClass */
    public function withActions(string $actionsClass): self
    {
        return $this->advanced('actions_class', $actionsClass);
    }

    public function actionAuthorize(string $id, string $ability, mixed $arguments = [], ?RecordTarget $target = null): self
    {
        return $this->advanced('action_policy.'.$id, new ActionPolicy($ability, $arguments, $target));
    }

    private function advanced(string $key, mixed $value): self
    {
        if (array_key_exists($key, $this->patch) && ! in_array($key, ['multi_order', 'search_match', 'query', 'actions_class'], true)) {
            throw DapterException::configuration();
        }
        $copy = clone $this;
        $copy->patch[$key] = $value;

        return $copy;
    }

    public function driver(string $alias): self
    {
        return $this->with(OptionKey::Driver, $alias);
    }

    /**
     * @internal
     *
     * @return list<FieldSetting>
     */
    public function fieldSettings(): array
    {
        return $this->fields;
    }

    private function withField(FieldSetting $setting): self
    {
        foreach ($this->fields as $existing) {
            if ($existing->key() === $setting->key()) {
                throw DapterException::configuration();
            }
        }
        $copy = clone $this;
        $copy->fields[] = $setting;

        return $copy;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function explicitValues(): array
    {
        return $this->patch;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function baselineValues(): array
    {
        return $this->baseline;
    }

    private function with(OptionKey $key, mixed $value): self
    {
        $copy = clone $this;
        $copy->patch[$key->value] = $value;

        return $copy;
    }
}
