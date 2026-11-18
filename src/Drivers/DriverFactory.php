<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Contracts\CollectionSource;
use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\EloquentSource;
use ArtisanToolbox\Dapter\Contracts\StoreDriver;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;

final class DriverFactory
{
    public function make(DataSource $source, ?ResolvedStoreOptions $options = null): StoreDriver
    {
        $this->sourceRequirements($source, $options);

        if ($options?->driver !== null) {
            $driver = resolve(DriverRegistry::class)->make($options->driver, $source);
            $this->driverRequirements($driver, $options);

            return $driver;
        }

        if ($source instanceof CollectionSource) {
            return new CollectionStoreDriver(new Collection($source->source()));
        }

        if ($source instanceof EloquentSource) {
            $builder = $source->source();
            $connection = $builder->getQuery()->getConnection();

            if (! $connection instanceof Connection) {
                throw DapterException::configuration();
            }

            foreach ([...($options?->global->filters ?? []), ...($options?->global->orders ?? [])] as $definition) {
                $databases = $definition->options?->requires->databases ?? [];

                if ($databases !== [] && ! in_array($connection->getDriverName(), $databases, true)) {
                    throw DapterException::configuration();
                }
            }

            return match ($connection->getDriverName()) {
                'sqlite' => new SqliteStoreDriver($builder, $options?->query),
                'mysql' => new MySqlStoreDriver($builder, $options?->query),
                'pgsql' => new PostgresStoreDriver($builder, $options?->query),
                default => throw DapterException::configuration(),
            };
        }

        throw DapterException::configuration();
    }

    /** @internal */
    public function profiled(DataSource $source, ResolvedStoreOptions $options): ProfiledDriver
    {
        if ($options->driver === null) {
            $driver = $this->make($source, $options);

            return new ProfiledDriver($driver, $driver->capabilities());
        }
        $this->sourceRequirements($source, $options);
        $profiled = resolve(DriverRegistry::class)->profiled($options->driver, $source, $options);
        $this->driverRequirements($profiled->driver, $options);

        return $profiled;
    }

    private function sourceRequirements(DataSource $source, ?ResolvedStoreOptions $options): void
    {
        foreach ([...($options?->global->filters ?? []), ...($options?->global->orders ?? [])] as $definition) {
            $requires = $definition->options?->requires;

            if ($requires?->sourceType !== null && ! $source instanceof ($requires->sourceType) || $requires?->driver !== null && $requires->driver !== $options?->driver || ($requires->databases ?? []) !== [] && ! $source instanceof EloquentSource) {
                throw DapterException::configuration();
            }
        }
    }

    private function driverRequirements(StoreDriver $driver, ResolvedStoreOptions $options): void
    {
        foreach ([...($options->global->filters ?? []), ...($options->global->orders ?? [])] as $definition) {
            $databases = $definition->options?->requires->databases ?? [];

            if ($databases !== [] && (! $driver instanceof EloquentStoreDriver || ! in_array($driver->database(), $databases, true))) {
                throw DapterException::configuration();
            }
        }
    }
}
