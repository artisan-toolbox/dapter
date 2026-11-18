<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Http\DapterException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/** @internal */
final readonly class BrowserStatePolicy
{
    public function __construct(public int|CarbonImmutable $ttl = 240, public ?string $key = null) {}

    public static function configuredMinutes(mixed $configuration): int
    {
        if (! is_array($configuration) || array_diff(array_keys($configuration), ['ttl']) !== [] || ! array_key_exists('ttl', $configuration) || ! self::validMinutes($configuration['ttl'])) {
            throw DapterException::configuration();
        }

        return $configuration['ttl'];
    }

    /** @param array<string,mixed> $values */
    public static function resolve(array $values): self
    {
        $ttl = array_key_exists('state_ttl', $values) ? $values['state_ttl'] : 240;
        $key = $values['state_key'] ?? null;

        if ((! self::validMinutes($ttl) && ! $ttl instanceof CarbonInterface) || ($ttl instanceof CarbonInterface && ($ttl->getTimestampMs() <= 0 || $ttl->getTimestampMs() > 9007199254740991)) || (array_key_exists('state_key', $values) && (! is_string($key) || trim($key) === '' || preg_match('/[\x00-\x1F\x7F]/', $key)))) {
            throw DapterException::configuration();
        }

        return new self($ttl instanceof CarbonInterface ? CarbonImmutable::instance($ttl) : $ttl, $key);
    }

    private static function validMinutes(mixed $value): bool
    {
        return is_int($value) && $value > 0 && $value <= intdiv(9007199254740991 - now()->getTimestampMs(), 60000);
    }

    /** @return array{key:string,version:string,expiration:array{minutes:int}|array{at:int}} */
    public function toArray(string $alias, string $version): array
    {
        return [
            'key' => $this->key ?? implode('_', array_map(Str::snake(...), explode('.', $alias))),
            'version' => $version,
            'expiration' => $this->ttl instanceof CarbonImmutable ? ['at' => $this->ttl->getTimestampMs()] : ['minutes' => $this->ttl],
        ];
    }
}
