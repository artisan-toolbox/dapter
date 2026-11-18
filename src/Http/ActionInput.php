<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as MatchedRoute;
use Illuminate\Support\Facades\Route;
use stdClass;
use Symfony\Component\HttpFoundation\Response;
use WeakMap;

/** @internal */
final class ActionInput
{
    /** @var WeakMap<Request, array<array-key, mixed>>|null */
    private static ?WeakMap $formBodies = null;

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->header('X-Dapter-Action') !== '1' || $request->isJson() || ! Route::getRoutes()->getByName('dapter.action')?->matches($request)) {
            return $next($request);
        }

        $formBodies = self::$formBodies ??= new WeakMap;
        $formBodies[$request] = $request->request->all();

        try {
            return $next($request);
        } finally {
            unset($formBodies[$request]);
        }
    }

    public static function value(Request $request, string $key): mixed
    {
        $route = $request->route();

        if (! $route instanceof MatchedRoute || ! $route->named('dapter.action') || $request->files->has($key)) {
            return null;
        }

        if ($request->isJson()) {
            $payload = json_decode($request->getContent());

            return $payload instanceof stdClass && property_exists($payload, $key) ? $payload->{$key} : null;
        }

        return self::$formBodies[$request][$key] ?? null;
    }
}
