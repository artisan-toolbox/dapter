<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use ArtisanToolbox\Dapter\Drivers\FilterClause;
use ArtisanToolbox\Dapter\Drivers\OrderTerm;
use ArtisanToolbox\Dapter\Drivers\ReadSelection;
use ArtisanToolbox\Dapter\Drivers\SearchClause;
use ArtisanToolbox\Dapter\Enums\EmptySelectionPolicy;
use ArtisanToolbox\Dapter\Enums\ErrorCode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\OrderKind;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\ReadProtocolVersion;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use ArtisanToolbox\Dapter\Support\ScalarValue;
use ArtisanToolbox\Dapter\Support\TrustedHandlerBinder;
use ArtisanToolbox\Dapter\Support\VectorValue;
use JsonException;
use stdClass;

final readonly class ReadIntent
{
    private function __construct(private ?stdClass $state, private int $protocol = 1) {}

    public static function fromJson(string $json): self
    {
        try {
            $input = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            if (in_array($exception->getCode(), [JSON_ERROR_DEPTH, JSON_ERROR_INVALID_PROPERTY_NAME], true)) {
                throw DapterException::validation();
            }

            throw new DapterException(ErrorKind::Validation, ErrorCode::MalformedJson, 400);
        }

        if ($input instanceof stdClass && ($input->version ?? null) === 2) {
            self::jsonNumbers($json);

            return self::v2($input);
        }
        self::safeNumbers($input);

        if (! $input instanceof stdClass || ! self::keys($input, ['version'], ['state']) || $input->version !== ReadProtocolVersion::V1->value) {
            throw DapterException::validation();
        }

        if (! property_exists($input, 'state')) {
            return new self(null);
        }
        $state = $input->state;

        if (! $state instanceof stdClass || ! self::keys($state, ['version', 'fields', 'global', 'searchMatch', 'order', 'paging']) || $state->version !== ReadProtocolVersion::V1->value || ! $state->fields instanceof stdClass || ! $state->global instanceof stdClass || ! is_string($state->searchMatch) || ! $state->order instanceof stdClass || ! self::keys($state->order, ['kind']) || ! is_string($state->order->kind) || ! $state->paging instanceof stdClass || ! self::keys($state->paging, ['mode', 'current', 'per_page']) || ! is_string($state->paging->mode) || ! is_int($state->paging->current) || $state->paging->current < 1 || ! is_int($state->paging->per_page) || $state->paging->per_page < 1) {
            throw DapterException::validation();
        }

        return new self($state);
    }

    public function validate(ResolvedStoreOptions $options): OffsetRequest
    {
        $paging = $this->paging($options);

        if (! $paging instanceof OffsetRequest) {
            throw DapterException::unsupported();
        }

        return $paging;
    }

    public function paging(ResolvedStoreOptions $options): OffsetRequest|SimpleRequest|CursorRequest
    {
        if ($this->protocol === 1 && $options->mode !== PagingMode::Offset) {
            throw DapterException::unsupported();
        }

        if ($options->mode === PagingMode::Simple && $options->size >= 9007199254740991) {
            throw DapterException::validation();
        }

        if ($this->state === null) {
            return match ($options->mode) {
                PagingMode::Offset => new OffsetRequest(1, $options->size),
                PagingMode::Simple => new SimpleRequest(1, $options->size),
                PagingMode::Cursor => new CursorRequest(null, $options->size),
            };
        }

        if ($this->protocol === 1 && (get_object_vars($this->state->fields) !== [] || get_object_vars($this->state->global) !== [] || SearchMatch::tryFrom($this->state->searchMatch) !== SearchMatch::All || OrderKind::tryFrom($this->state->order->kind) !== OrderKind::Default || PagingMode::tryFrom($this->state->paging->mode) !== PagingMode::Offset)) {
            throw DapterException::unsupported();
        }

        if ($this->state->paging->mode !== $options->mode->value) {
            throw DapterException::unsupported();
        }
        $size = $this->state->paging->per_page;

        if (! in_array($size, $options->sizes, true)) {
            throw DapterException::validation();
        }

        if ($options->mode === PagingMode::Cursor) {
            return new CursorRequest($this->state->paging->cursor, $size);
        }
        $current = $this->state->paging->current;

        if ($options->mode === PagingMode::Simple) {
            if ($current - 1 > intdiv(9007199254740991 - $size - 1, $size)) {
                throw DapterException::validation();
            }

            return new SimpleRequest($current, $size);
        }

        return new OffsetRequest($current, $size);
    }

    public function version(): int
    {
        return $this->protocol;
    }

    private static function v2(stdClass $input): self
    {
        if (! self::keys($input, ['version'], ['state'])) {
            throw DapterException::validation();
        }

        if (! property_exists($input, 'state')) {
            return new self(null, 2);
        }
        $state = $input->state;

        if (! $state instanceof stdClass || ! self::keys($state, ['version', 'fields', 'global', 'searchMatch', 'order', 'paging']) || $state->version !== 2 || ! $state->fields instanceof stdClass || ! $state->global instanceof stdClass || ! is_string($state->searchMatch) || ! $state->order instanceof stdClass || ! is_string($state->order->kind ?? null) || ! $state->paging instanceof stdClass || ! self::validPosition($state->paging)) {
            throw DapterException::validation();
        }

        return new self($state, 2);
    }

    private static function validPosition(stdClass $paging): bool
    {
        if (! is_string($paging->mode ?? null) || ! is_int($paging->per_page ?? null) || $paging->per_page < 1 || $paging->per_page > 9007199254740991) {
            return false;
        }

        if ($paging->mode === 'cursor') {
            return self::keys($paging, ['mode', 'cursor', 'per_page']) && ($paging->cursor === null || is_string($paging->cursor));
        }

        return self::keys($paging, ['mode', 'current', 'per_page']) && is_int($paging->current) && $paging->current >= 1 && $paging->current <= 9007199254740991;
    }

    public function selection(ResolvedStoreOptions $options): ReadSelection
    {
        if ($options->mode === PagingMode::Cursor) {
            foreach ($options->query->fixedOrder ?? [] as $term) {
                if ($term->sortType !== SortType::Native) {
                    throw DapterException::unsupported();
                }
            }
        }

        if ($this->protocol === 1 || $this->state === null) {
            return new ReadSelection(searchMatch: $this->protocol === 1 ? SearchMatch::All : $options->searchMatch);
        }
        $state = $this->state;

        if ($state->paging->mode !== $options->mode->value || ! in_array(SearchMatch::tryFrom($state->searchMatch), $options->allowedSearchMatches, true)) {
            throw DapterException::unsupported();
        }
        $searches = [];
        $filters = [];
        $fields = new stdClass;
        foreach (get_object_vars($state->fields) as $name => $selection) {
            if (! $selection instanceof stdClass || ! self::keys($selection, [], ['search', 'filters'])) {
                throw DapterException::validation();
            }
            $field = $options->fields[$name] ?? null;

            if ($field === null) {
                throw DapterException::unsupported();
            }
            $normalized = new stdClass;

            if (property_exists($selection, 'search')) {
                $search = $selection->search;

                if (! $search instanceof stdClass || ! self::keys($search, ['value', 'matchType']) || ! is_string($search->matchType)) {
                    throw DapterException::validation();
                }
                $match = MatchType::tryFrom($search->matchType);

                if ($field->search === null || $match === null || ! in_array($match, $field->search->matchTypes, true)) {
                    throw DapterException::unsupported();
                }
                $value = $match === MatchType::Vector
                    ? VectorValue::normalize($search->value, $field->search->options->vector ?? throw DapterException::configuration())
                    : ScalarValue::search($search->value, $field->search->valueType);

                if ($value === null || is_string($value) && preg_match('//u', $value) !== 1) {
                    throw DapterException::validation();
                }

                if ($match === MatchType::FullText && $value !== '') {
                    self::fullTextInput($value);
                }

                if ($value !== '') {
                    $searches[] = new SearchClause($name, $field->target, $value, $match, $field->search->valueType);
                    $normalized->search = ['value' => $match === MatchType::Vector ? $value : $search->value, 'matchType' => $match->value];
                }
            }

            if (property_exists($selection, 'filters')) {
                if (! $selection->filters instanceof stdClass) {
                    throw DapterException::validation();
                }
                $normalizedFilters = new stdClass;
                foreach (get_object_vars($selection->filters) as $id => $filterSelection) {
                    if (! $filterSelection instanceof stdClass || ! self::keys($filterSelection, ['value'])) {
                        throw DapterException::validation();
                    }
                    $definition = $field->filters[$id] ?? null;

                    if ($definition === null) {
                        throw DapterException::unsupported();
                    }
                    $raw = $filterSelection->value;

                    if ($definition->multiple && (! is_array($raw) || ! array_is_list($raw))) {
                        throw DapterException::validation();
                    }

                    if (! is_array($definition->values)) {
                        throw DapterException::configuration();
                    }
                    $selected = $definition->multiple ? $raw : [$raw];

                    if ($selected === []) {
                        if ($definition->empty === EmptySelectionPolicy::Reject) {
                            throw DapterException::validation();
                        }

                        continue;
                    }
                    $values = [];
                    foreach ($selected as $value) {
                        if ($value !== null && $definition->valueType === null) {
                            throw DapterException::validation();
                        }
                        $value = $value === null ? null : ScalarValue::normalize($value, $definition->valueType);

                        if (! in_array($value, $definition->values, true)) {
                            throw DapterException::validation();
                        }

                        if (! in_array($value, $values, true)) {
                            $values[] = $value;
                        }
                    }
                    $filters[] = new FilterClause($name, $id, $field->target, $values, $definition->valueType);
                    $normalizedFilters->{$id} = ['value' => $definition->multiple ? $values : $values[0]];
                }

                if (get_object_vars($normalizedFilters) !== []) {
                    $normalized->filters = $normalizedFilters;
                }
            }

            if (get_object_vars($normalized) !== []) {
                $fields->{$name} = $normalized;
            }
        }
        $order = [];

        if ($state->order->kind === 'default') {
            if (! self::keys($state->order, ['kind'])) {
                throw DapterException::validation();
            }
        } elseif ($state->order->kind === 'fields') {
            if (! self::keys($state->order, ['kind', 'terms']) || ! is_array($state->order->terms) || ! array_is_list($state->order->terms) || $state->order->terms === []) {
                throw DapterException::validation();
            }

            if (count($state->order->terms) > ($options->multiOrder ? $options->maxOrder : 1)) {
                throw DapterException::unsupported();
            }
            foreach ($state->order->terms as $term) {
                if (! $term instanceof stdClass || ! self::keys($term, ['field', 'direction', 'sortType']) || ! is_string($term->field) || ! is_string($term->direction) || ! is_string($term->sortType)) {
                    throw DapterException::validation();
                }
                $definition = $options->fields[$term->field] ?? null;
                $direction = SortDirection::tryFrom($term->direction);
                $sort = SortType::tryFrom($term->sortType);

                if ($definition?->order === null || $direction === null || $sort === null || ! in_array($direction, $definition->order->directions, true) || ! in_array($sort, $definition->order->sortTypes, true)) {
                    throw DapterException::unsupported();
                }

                if (in_array($term->field, array_map(static fn (OrderTerm $existing): string => $existing->field, $order), true)) {
                    throw DapterException::validation();
                }
                $order[] = new OrderTerm($term->field, $definition->target, $direction, $sort, $definition->order->valueType);
            }
        } elseif ($state->order->kind === 'preset') {
            if (! self::keys($state->order, ['kind', 'id']) || ! is_string($state->order->id)) {
                throw DapterException::validation();
            }

            if (! isset($options->global?->orders[$state->order->id])) {
                throw DapterException::unsupported();
            }
        } else {
            throw DapterException::unsupported();
        }

        if (! self::keys($state->global, [], ['search', 'filters'])) {
            throw DapterException::validation();
        }
        $globalSearch = null;
        $globalSearches = [];

        if (property_exists($state->global, 'search')) {
            $search = $state->global->search;

            if (! $search instanceof stdClass || ! self::keys($search, ['value']) || ! is_string($search->value) || preg_match('//u', $search->value) !== 1) {
                throw DapterException::validation();
            }

            if ($options->global?->search === null) {
                throw DapterException::unsupported();
            }

            if ($search->value !== '') {
                $globalSearch = $search->value;
                foreach ($options->global->search as $id => $target) {
                    if ($target->matchType === MatchType::FullText) {
                        self::fullTextInput($globalSearch);
                    }
                    $value = ScalarValue::globalSearch($globalSearch, $target->valueType);

                    if ($value !== null) {
                        $globalSearches[] = new SearchClause($id, $target->target, $value, $target->matchType, $target->valueType);
                    }
                }
            }
        }
        $globalFilters = [];

        if (property_exists($state->global, 'filters')) {
            if (! $state->global->filters instanceof stdClass) {
                throw DapterException::validation();
            }
            foreach (get_object_vars($state->global->filters) as $id => $selected) {
                if (! $selected instanceof stdClass || ! self::keys($selected, ['value'])) {
                    throw DapterException::validation();
                }
                $definition = $options->global?->filters[$id] ?? null;

                if ($definition === null) {
                    throw DapterException::unsupported();
                }

                if ($definition->multiple && (! is_array($selected->value) || ! array_is_list($selected->value))) {
                    throw DapterException::validation();
                }
                $values = $definition->multiple ? $selected->value : [$selected->value];

                if ($values === []) {
                    if ($definition->empty === EmptySelectionPolicy::Reject) {
                        throw DapterException::validation();
                    }

                    continue;
                }
                $accepted = [];
                foreach ($values as $value) {
                    if ($definition->valueType !== null) {
                        $value = ScalarValue::normalize($value, $definition->valueType);
                    }

                    if (! in_array($value, $definition->values, true)) {
                        throw DapterException::validation();
                    }

                    if (! in_array($value, $accepted, true)) {
                        $accepted[] = $value;
                    }
                }
                $globalFilters[$id] = $accepted;
            }
        }

        if ($options->mode !== PagingMode::Offset && $state->order->kind === 'preset') {
            throw DapterException::unsupported();
        }

        if ($options->mode === PagingMode::Cursor) {
            foreach ($order as $term) {
                if ($term->sortType !== SortType::Native) {
                    throw DapterException::unsupported();
                }
            }
        }
        $binder = new TrustedHandlerBinder;
        foreach ($globalFilters as $id => $values) {
            $definition = $options->global->filters[$id];
            $binder->bindValue($binder->describe($definition->apply), $definition->multiple ? $values : $values[0], $definition->options);
        }

        if ($state->order->kind === 'preset') {
            $binder->describe($options->global->orders[$state->order->id]->apply);
        }

        return new ReadSelection($searches, $filters, $order, $fields, $globalSearches, $globalSearch, $globalFilters, $state->order->kind === 'preset' ? $state->order->id : null, SearchMatch::from($state->searchMatch));
    }

    private static function fullTextInput(mixed $value): void
    {
        if (! is_string($value) || strlen($value) > 512 || str_contains($value, "\0") || preg_match('//u', $value) !== 1) {
            throw DapterException::validation();
        }
    }

    /**
     * @param  list<string>  $required
     * @param  list<string>  $optional
     */
    private static function keys(stdClass $value, array $required, array $optional = []): bool
    {
        $keys = array_keys(get_object_vars($value));

        return array_diff($required, $keys) === [] && array_diff($keys, [...$required, ...$optional]) === [];
    }

    private static function jsonNumbers(string $json): void
    {
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|(-?(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/s', $json, $tokens, PREG_SET_ORDER);
        foreach ($tokens as $token) {
            if (! isset($token[1])) {
                continue;
            }
            $value = (float) $token[1];
            $significand = explode('e', strtolower($token[1]))[0];

            if (! is_finite($value) || abs($value) > 9007199254740991 || ($value == 0.0 && preg_match('/[1-9]/', $significand))) {
                throw DapterException::validation();
            }
        }
    }

    private static function safeNumbers(mixed $value): void
    {
        if (is_float($value) || (is_int($value) && abs($value) > 9007199254740991)) {
            throw DapterException::validation();
        }

        if (is_array($value) || $value instanceof stdClass) {
            foreach (is_array($value) ? $value : get_object_vars($value) as $child) {
                self::safeNumbers($child);
            }
        }
    }
}
