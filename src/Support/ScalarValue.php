<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;

/** @internal */
final class ScalarValue
{
    /** @param list<mixed> $values */
    public static function infer(array $values): ValueType
    {
        $type = null;
        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }
            $next = match (true) {
                is_string($value) => ValueType::String,
                is_bool($value) => ValueType::Boolean,
                is_int($value), is_float($value) && is_finite($value) && floor($value) === $value => ValueType::Integer,
                is_float($value) => ValueType::Number,
                default => throw DapterException::configuration(),
            };

            if ($type !== null && $type !== $next) {
                throw DapterException::configuration();
            }
            $type = $next;
        }

        return $type ?? ValueType::String;
    }

    public static function normalize(mixed $value, ValueType $type, bool $configuration = false): string|int|float|bool|null
    {
        $valid = $value === null || match ($type) {
            ValueType::String => is_string($value),
            ValueType::Boolean => is_bool($value),
            ValueType::Integer => (is_int($value) || is_float($value)) && is_finite((float) $value) && abs($value) <= 9007199254740991 && floor((float) $value) === (float) $value,
            ValueType::Number => (is_int($value) || is_float($value)) && is_finite((float) $value) && abs($value) <= 9007199254740991,
        };

        if (! $valid) {
            throw $configuration ? DapterException::configuration() : DapterException::validation();
        }

        return $value === null ? null : match ($type) {
            ValueType::Integer => (int) $value,
            ValueType::Number => (float) $value,
            default => $value,
        };
    }

    public static function globalSearch(string $value, ValueType $type): string|int|float|bool|null
    {
        if ($type === ValueType::String) {
            return $value;
        }

        if ($type === ValueType::Boolean) {
            return match ($value) {
                'true' => true, 'false' => false, default => null,
            };
        }
        $pattern = $type === ValueType::Integer ? '/\\A-?(?:0|[1-9][0-9]*)\\z/D' : '/\\A-?(?:0|[1-9][0-9]*)(?:\\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\\z/D';

        if (! preg_match($pattern, $value)) {
            return null;
        }
        $number = (float) $value;

        if (! is_finite($number) || abs($number) > 9007199254740991 || ($number == 0.0 && preg_match('/[1-9]/', explode('e', strtolower($value))[0]))) {
            return null;
        }

        return $type === ValueType::Integer ? (int) $number : $number;
    }

    public static function search(mixed $value, ValueType $type): string|int|float|bool|null
    {
        if ($value === '') {
            return '';
        }

        return self::normalize($value, $type);
    }
}
