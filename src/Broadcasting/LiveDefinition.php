<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use ArtisanToolbox\Dapter\Contracts\DeliveryContextFactory;
use ArtisanToolbox\Dapter\Contracts\PublicRowProjection;
use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\AdvancedOptionsResolver;
use ArtisanToolbox\Dapter\Options\BroadcastOptions;
use ArtisanToolbox\Dapter\Options\ClassOptions;
use ArtisanToolbox\Dapter\Options\GateCheck;
use ArtisanToolbox\Dapter\Options\ProjectionOptions;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use ArtisanToolbox\Dapter\Paging\StableFingerprint;
use Illuminate\Auth\AuthManager;
use ReflectionClass;

/** @internal */
final readonly class LiveDefinition
{
    public string $guard;

    public AccessMode $access;

    public ?GateCheck $authorization;

    public ?string $resource;

    public BroadcastOptions $broadcasting;

    public string $pattern;

    public string $fingerprint;

    public bool $strictLiteralSearch;

    public function __construct(public string $alias, public LiveSourceRegistration $registration)
    {
        $values = (new ClassOptions)->values($registration->source, $registration->options);
        $configured = (new AdvancedOptionsResolver)->config($registration->options->baselineValues()['declarations'] ?? []);
        $this->strictLiteralSearch = AdvancedOptionsResolver::strictLiteralSearch(array_replace($configured, $values));
        [$resource, $broadcasting] = ProjectionOptions::parse($values);
        $guard = $values['guard'] ?? null;
        $access = $values['access'] ?? AccessMode::Required;
        $gate = $values['authorization'] ?? null;
        $provider = is_string($guard) ? config('auth.guards.'.$guard.'.provider') : null;

        if ($broadcasting === null || ! is_string($guard) || ! is_string($provider) || ! is_array(config('auth.providers.'.$provider)) || ! $access instanceof AccessMode || $gate !== null && ! $gate instanceof GateCheck || ! is_subclass_of($registration->contexts, DeliveryContextFactory::class) || ! (new ReflectionClass($registration->contexts))->isInstantiable() || ! self::segment($registration->generation) || $registration->producerAuthorize === '') {
            throw DapterException::configuration();
        }

        if (resolve(AuthManager::class)->createUserProvider($provider) === null) {
            throw DapterException::configuration();
        }
        $channel = $broadcasting->channel;

        if ($channel instanceof PublicChannel) {
            if ($access !== AccessMode::Public || $resource !== null || $registration->publicProjection === null || ! is_subclass_of($registration->publicProjection, PublicRowProjection::class) || ! (new ReflectionClass($registration->publicProjection))->isInstantiable() || $registration->producerAuthorize === null || ! self::segment($channel->projection)) {
                throw DapterException::configuration();
            }
            $pattern = $channel->name;
            self::validatePattern($pattern, false);
        } elseif ($channel instanceof PrivateChannel) {
            if ($registration->publicProjection !== null) {
                throw DapterException::configuration();
            }
            $pattern = str_replace('{source}', $alias, $channel->pattern);
            self::validatePattern($pattern, true);
        } else {
            throw DapterException::configuration();
        }
        $this->guard = $guard;
        $this->access = $access;
        $this->authorization = $gate;
        $this->resource = $resource;
        $this->broadcasting = $broadcasting;
        $this->pattern = $pattern;
        $this->fingerprint = StableFingerprint::of([$alias, $registration->source, $registration->contexts, $registration->generation, $registration->publicProjection, $registration->producerAuthorize, $guard, $access, $gate?->ability, $resource, self::transport($broadcasting), $this->strictLiteralSearch]);
    }

    public static function segment(string $value): bool
    {
        return (bool) preg_match('/\A[A-Za-z0-9_=-]{1,64}\z/D', $value);
    }

    private static function validatePattern(string $pattern, bool $private): void
    {
        if (str_starts_with($pattern, 'private-') || str_starts_with($pattern, 'presence-') || strlen(str_replace('{context}', str_repeat('x', 43), $pattern)) > 128 || ($private ? substr_count($pattern, '{context}') !== 1 : str_contains($pattern, '{'))) {
            throw DapterException::configuration();
        }
        foreach (explode('.', $pattern) as $segment) {
            if (! ($private && $segment === '{context}') && ! self::segment($segment)) {
                throw DapterException::configuration();
            }
        }
    }

    public function assertOptions(ResolvedStoreOptions $options): void
    {
        if ($options->strictLiteralSearch !== $this->strictLiteralSearch || $options->guard !== $this->guard || $options->resource !== $this->resource || $options->broadcasting === null || self::transport($options->broadcasting) !== self::transport($this->broadcasting)) {
            throw DapterException::configuration();
        }
    }

    /** @return list<mixed> */
    private static function transport(BroadcastOptions $options): array
    {
        $channel = $options->channel;

        return [$options->identifierField, $options->identity, $options->revisions, $channel instanceof PrivateChannel ? ['private', $channel->pattern, $channel->context, $channel->authorize] : ($channel instanceof PublicChannel ? ['public', $channel->name, $channel->projection] : null)];
    }

    public static function overlaps(string $first, string $second): bool
    {
        $left = explode('.', $first);
        $right = explode('.', $second);

        if (count($left) !== count($right)) {
            return false;
        }
        foreach ($left as $index => $segment) {
            if (! preg_match('/\{[^}]+\}/', $segment) && ! preg_match('/\{[^}]+\}/', $right[$index]) && $segment !== $right[$index]) {
                return false;
            }
        }

        return true;
    }
}
