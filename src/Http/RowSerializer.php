<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use Illuminate\Contracts\Support\Arrayable;
use JsonException;
use JsonSerializable;
use stdClass;

final class RowSerializer
{
    /**
     * @param  list<mixed>  $rows
     * @return list<mixed>
     */
    public function serialize(array $rows): array
    {
        $result = [];
        foreach ($rows as $row) {
            if ($row instanceof Arrayable) {
                $row = $row->toArray();
            } elseif ($row instanceof JsonSerializable) {
                $row = $row->jsonSerialize();
            }
            $this->validate($row, 0);
            $result[] = $row;
        }

        try {
            json_encode($result, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DapterException::configuration();
        }

        return $result;
    }

    private function validate(mixed $value, int $depth): void
    {
        if ($depth > 100 || is_resource($value) || (is_object($value) && $value::class !== stdClass::class) || (is_float($value) && (! is_finite($value) || abs($value) > 9007199254740991)) || (is_int($value) && abs($value) > 9007199254740991)) {
            throw DapterException::configuration();
        }

        if (is_array($value) || $value instanceof stdClass) {
            foreach (is_array($value) ? $value : get_object_vars($value) as $child) {
                $this->validate($child, $depth + 1);
            }
        }
    }
}
