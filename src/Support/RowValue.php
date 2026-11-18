<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use ArtisanToolbox\Dapter\Http\DapterException;
use Illuminate\Contracts\Support\Arrayable;
use PropertyHookType;
use ReflectionProperty;
use stdClass;

/** @internal */
final class RowValue
{
    public static function output(mixed $row, string $key): mixed
    {
        if ($row instanceof Arrayable) {
            $row = $row->toArray();
        }

        if (is_array($row) && array_key_exists($key, $row)) {
            return $row[$key];
        }

        if ($row instanceof stdClass && property_exists($row, $key)) {
            return $row->{$key};
        }

        throw DapterException::configuration();
    }

    public static function get(mixed $row, string $target): mixed
    {
        if ($row instanceof Arrayable) {
            $row = $row->toArray();
        }
        $missing = new stdClass;
        foreach (explode('.', $target) as $segment) {
            $value = data_get($row, [$segment], $missing);

            if ($value === $missing) {
                if (! is_object($row) || ! property_exists($row, $segment)) {
                    throw DapterException::configuration();
                }
                $property = new ReflectionProperty($row, $segment);

                if (! $property->isPublic() || $property->isStatic() || (! $property->isInitialized($row) && ! $property->hasHook(PropertyHookType::Get))) {
                    throw DapterException::configuration();
                }
                $value = null;
            }
            $row = $value;
        }

        return $row;
    }
}
