<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\FieldOptionsResolver;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use Closure;
use ReflectionFunction;
use ReflectionNamedType;

final class DriverRegistry
{
    /** @var array<string, Closure> */
    private array $factories = [];

    /**
     * @template TSource of DataSource
     *
     * @param  Closure(TSource): StoreDriver  $factory
     */
    public function register(string $alias, Closure $factory): void
    {
        if (! FieldOptionsResolver::identifier($alias) || in_array(strtolower($alias), array_map('strtolower', array_keys($this->factories)), true)) {
            throw DapterException::configuration();
        }
        $this->factories[$alias] = $factory;
    }

    public function make(string $alias, DataSource $source): StoreDriver
    {
        return $this->profiled($alias, $source)->driver;
    }

    /** @internal */
    public function profiled(string $alias, DataSource $source, ?ResolvedStoreOptions $options = null): ProfiledDriver
    {
        $factory = $this->factories[$alias] ?? throw DapterException::configuration();
        $parameter = (new ReflectionFunction($factory))->getParameters()[0] ?? null;
        $type = $parameter?->getType();

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && ! is_a($source, $type->getName())) {
            throw DapterException::configuration();
        }
        $driver = (new ReflectionFunction($factory))->invoke($source);

        if (! $driver instanceof StoreDriver) {
            throw DapterException::configuration();
        }

        if ($driver instanceof EloquentStoreDriver && $options !== null) {
            $driver = $driver->withQueryOptions($options->query);
        }
        $profile = $driver->capabilities();
        $family = $profile->sourceType;

        if ($family !== null && ! $source instanceof $family) {
            throw DapterException::configuration();
        }

        return new ProfiledDriver($driver, $profile);
    }
}
