<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use ArtisanToolbox\Dapter\Http\DapterException;

/** @internal */
final class RowIdentity
{
    public static function validate(mixed $value): int|string
    {
        if (is_int($value) && $value >= -9007199254740991 && $value <= 9007199254740991 || is_string($value) && $value !== '' && preg_match('//u', $value)) {
            return $value;
        }

        throw DapterException::configuration();
    }

    public static function key(int|string $value): string
    {
        return is_int($value) ? 'i:'.$value : 's:'.$value;
    }

    public static function revision(string $value): string
    {
        if (! preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value) || strlen($value) > 20 || strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0) {
            throw DapterException::configuration();
        }

        return $value;
    }

    /** @return list<int|string> */
    public static function batch(mixed $identified): array
    {
        $values = is_array($identified) ? $identified : [$identified];

        if (! array_is_list($values) || count($values) > 100) {
            throw DapterException::configuration();
        }
        $result = [];
        $seen = [];
        foreach ($values as $value) {
            $value = self::validate($value);

            if (is_string($value) && strlen($value) > 1024) {
                throw DapterException::configuration();
            }
            $key = self::key($value);

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $value;
            }
        }

        return $result;
    }
}
