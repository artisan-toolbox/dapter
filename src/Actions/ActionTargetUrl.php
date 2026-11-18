<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Actions;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderUtils;

/** @internal */
final class ActionTargetUrl
{
    private const string QUERY = 'dapter-target';

    /** @return array{target: string|int}|array{dapter-target: string} */
    public static function parameters(string|int $wire): array
    {
        if (is_int($wire) || rawurlencode($wire) === $wire && $wire !== '.' && $wire !== '..') {
            return ['target' => $wire];
        }

        return [self::QUERY => self::encode($wire)];
    }

    public static function matches(Request $request, ?string $path, string|int|null $wire): bool
    {
        if (! $request->query->has(self::QUERY)) {
            return $path === ($wire === null ? null : (string) $wire);
        }

        if ($path !== null || ! is_string($wire)) {
            return false;
        }

        // PHP collapses duplicate query keys and Laravel trims their values before dispatch.
        $query = HeaderUtils::parseQuery($request->server->getString('QUERY_STRING'), ignoreBrackets: true);
        foreach (array_keys($query) as $key) {
            if (str_starts_with((string) $key, self::QUERY.'[')) {
                return false;
            }
        }

        return ($query[self::QUERY] ?? null) === [self::encode($wire)];
    }

    private static function encode(string $wire): string
    {
        return rtrim(strtr(base64_encode($wire), '+/', '-_'), '=');
    }
}
