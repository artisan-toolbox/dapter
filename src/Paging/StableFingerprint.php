<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Paging;

use ArtisanToolbox\Dapter\Http\DapterException;
use BackedEnum;
use UnitEnum;

/** @internal */
final class StableFingerprint
{
    public static function of(mixed $value): string
    {
        return hash('sha256', json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    private static function normalize(mixed $value): mixed
    {
        if ($value instanceof UnitEnum) {
            return ['enum', $value::class, $value instanceof BackedEnum ? $value->value : $value->name];
        }

        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::normalize(...), $value);
        }

        if ($value === null || is_string($value) || is_bool($value) || is_int($value) || is_float($value) && is_finite($value)) {
            return $value;
        }

        throw DapterException::configuration();
    }
}
