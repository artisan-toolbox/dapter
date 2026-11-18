<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\VectorOptions;

/** @internal */
final class VectorValue
{
    /** @return list<float> */
    public static function normalize(mixed $value, VectorOptions $options): array
    {
        if (! is_array($value) || ! array_is_list($value) || count($value) !== $options->dimensions) {
            throw DapterException::validation();
        }
        $coordinates = [];
        foreach ($value as $coordinate) {
            if ((! is_int($coordinate) && ! is_float($coordinate)) || ! is_finite((float) $coordinate) || abs($coordinate) > 1000000) {
                throw DapterException::validation();
            }
            $converted = unpack('fcoordinate', pack('f', (float) $coordinate));

            if ($converted === false || ! is_float($converted['coordinate']) || ! is_finite($converted['coordinate'])) {
                throw DapterException::validation();
            }
            $coordinates[] = $converted['coordinate'];
        }

        return $coordinates;
    }
}
