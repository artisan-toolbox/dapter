<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\RowSerializer;
use ArtisanToolbox\Dapter\Support\RowIdentity;
use ArtisanToolbox\Dapter\Support\SourceResolver;
use stdClass;

/** @internal */
final class UpdateEnvelope
{
    /**
     * @return array<string,mixed> */
    public static function parse(mixed $value): array
    {
        $envelope = self::object($value, ['version', 'eventId', 'source', 'channel', 'data', 'records']);

        if ($envelope['version'] !== 1 || ! is_string($envelope['eventId']) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $envelope['eventId']) || ! is_string($envelope['source']) || ! SourceResolver::validName($envelope['source'])) {
            throw DapterException::configuration();
        }
        $channel = self::object($envelope['channel'], ['type', 'name', 'event']);

        if (! in_array($channel['type'], ['private', 'public'], true) || $channel['event'] !== '.dapter.updated' || ! is_string($channel['name']) || strlen($channel['name']) > 128 || str_starts_with($channel['name'], 'private-') || str_starts_with($channel['name'], 'presence-')) {
            throw DapterException::configuration();
        }
        foreach (explode('.', $channel['name']) as $segment) {
            if (! LiveDefinition::segment($segment)) {
                throw DapterException::configuration();
            }
        }

        if (! is_array($envelope['data']) || ! array_is_list($envelope['data']) || count($envelope['data']) !== 1) {
            throw DapterException::configuration();
        }
        $records = $envelope['records'] instanceof stdClass ? get_object_vars($envelope['records']) : $envelope['records'];

        if (! is_array($records) || ! in_array($records['mode'] ?? null, ['identified', 'revisioned'], true)) {
            throw DapterException::configuration();
        }
        $revisioned = $records['mode'] === 'revisioned';
        $records = self::object($records, $revisioned ? ['mode', 'format', 'rows'] : ['mode', 'rows']);

        if ($revisioned && $records['format'] !== 'uint64-decimal' || ! is_array($records['rows']) || ! array_is_list($records['rows']) || count($records['rows']) !== 1) {
            throw DapterException::configuration();
        }
        $row = self::object($records['rows'][0], $revisioned ? ['id', 'revision'] : ['id']);
        RowIdentity::validate($row['id']);

        if ($revisioned) {
            if (! is_string($row['revision'])) {
                throw DapterException::configuration();
            }
            RowIdentity::revision($row['revision']);
        }
        $data = (new RowSerializer)->serialize($envelope['data']);
        $envelope = [...$envelope, 'channel' => $channel, 'data' => $data, 'records' => [...$records, 'rows' => [$row]]];

        if (strlen(json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > 65536) {
            throw DapterException::configuration();
        }

        return $envelope;
    }

    /** @param list<string> $keys
     * @return array<string,mixed> */
    private static function object(mixed $value, array $keys): array
    {
        $value = $value instanceof stdClass ? get_object_vars($value) : $value;

        if (! is_array($value) || array_is_list($value)) {
            throw DapterException::configuration();
        }
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        if ($actual !== $keys) {
            throw DapterException::configuration();
        }

        return $value;
    }
}
