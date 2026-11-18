<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\SQLiteConnection;

/**
 * @template TModel of Model
 *
 * @extends EloquentStoreDriver<TModel>
 */
final readonly class SqliteStoreDriver extends EloquentStoreDriver
{
    /** @param Builder<TModel> $source */
    public function __construct(Builder $source, ?QueryOptions $options = null)
    {
        $connection = $source->getQuery()->getConnection();

        if (! $connection instanceof SQLiteConnection || $connection->getDriverName() !== 'sqlite') {
            throw DapterException::configuration();
        }
        parent::__construct($source, $options);
    }
}
