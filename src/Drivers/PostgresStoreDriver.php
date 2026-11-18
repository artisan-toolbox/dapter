<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;

/**
 * @template TModel of Model
 *
 * @extends EloquentStoreDriver<TModel>
 */
final readonly class PostgresStoreDriver extends EloquentStoreDriver
{
    /** @param Builder<TModel> $source */
    public function __construct(Builder $source, ?QueryOptions $options = null)
    {
        $connection = $source->getQuery()->getConnection();

        if (! $connection instanceof PostgresConnection || $connection->getDriverName() !== 'pgsql') {
            throw DapterException::configuration();
        }
        parent::__construct($source, $options);
    }
}
