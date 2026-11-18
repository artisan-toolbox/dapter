<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use ArtisanToolbox\Dapter\Contracts\PublicRowProjection;
use ArtisanToolbox\Dapter\Enums\RevisionFormat;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use ArtisanToolbox\Dapter\Support\FieldIdentity;
use ArtisanToolbox\Dapter\Support\RowIdentity;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
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

    /** @param list<mixed> $rows */
    public function prepare(array $rows, ResolvedStoreOptions $options, Request $reader, ?PublicRowProjection $projection = null): PreparedRows
    {
        $identity = $options->broadcasting?->identity;
        $identity = $identity === null ? new FieldIdentity($options->broadcasting?->identifierField) : resolve($identity);
        $revision = $options->broadcasting?->revisions;
        $revision = $revision === null ? null : resolve($revision);
        $data = [];
        $records = [];
        $seen = [];
        foreach ($rows as $raw) {
            if ($options->broadcasting !== null) {
                $id = RowIdentity::validate($identity->identify($raw));
                $key = RowIdentity::key($id);

                if (isset($seen[$key])) {
                    throw DapterException::configuration();
                }
                $seen[$key] = true;
                $records[] = $revision === null ? ['id' => $id] : ['id' => $id, 'revision' => RowIdentity::revision($revision->revision($raw))];
            }

            if ($projection !== null) {
                $row = $projection->project($raw);
            } elseif ($options->resource !== null) {
                $class = $options->resource;
                $row = $this->materialize(new $class($raw), $reader, 0);
            } else {
                $row = $this->serialize([$raw])[0];
            }
            $this->validate($row, 0);
            $data[] = $row;
        }

        try {
            $data = json_decode(json_encode($data, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw DapterException::configuration();
        }
        $metadata = $options->broadcasting === null ? ['mode' => 'unidentified'] : ($revision === null ? ['mode' => 'identified', 'rows' => $records] : ['mode' => 'revisioned', 'format' => RevisionFormat::Unsigned64Decimal->value, 'rows' => $records]);

        return new PreparedRows($data, $metadata);
    }

    private function materialize(mixed $value, Request $reader, int $depth): mixed
    {
        if ($depth > 100) {
            throw DapterException::configuration();
        }

        if ($value instanceof JsonResource) {
            return $this->materialize($value->resolve($reader), $reader, $depth + 1);
        }

        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->materialize($child, $reader, $depth + 1);
            }
        } elseif ($value instanceof stdClass) {
            $result = new stdClass;
            foreach (get_object_vars($value) as $key => $child) {
                $result->{$key} = $this->materialize($child, $reader, $depth + 1);
            }

            return $result;
        }

        return $value;
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
