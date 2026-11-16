<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use ArtisanToolbox\Dapter\Enums\ErrorCode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Enums\OrderKind;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\ReadProtocolVersion;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use JsonException;
use stdClass;

final readonly class ReadIntent
{
    private function __construct(private ?stdClass $state) {}

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
        if ($this->state === null) {
            return new OffsetRequest(1, $options->size);
        }

        if (get_object_vars($this->state->fields) !== [] || get_object_vars($this->state->global) !== [] || SearchMatch::tryFrom($this->state->searchMatch) !== SearchMatch::All || OrderKind::tryFrom($this->state->order->kind) !== OrderKind::Default || PagingMode::tryFrom($this->state->paging->mode) !== PagingMode::Offset) {
            throw DapterException::unsupported();
        }

        if (! in_array($this->state->paging->per_page, $options->sizes, true)) {
            throw DapterException::validation();
        }

        return new OffsetRequest($this->state->paging->current, $this->state->paging->per_page);
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
