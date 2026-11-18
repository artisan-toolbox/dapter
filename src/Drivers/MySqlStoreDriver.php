<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\QueryOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;

/**
 * @template TModel of Model
 *
 * @extends EloquentStoreDriver<TModel>
 */
final readonly class MySqlStoreDriver extends EloquentStoreDriver
{
    /** @param Builder<TModel> $source */
    public function __construct(Builder $source, ?QueryOptions $options = null)
    {
        $connection = $source->getQuery()->getConnection();

        if (! $connection instanceof MySqlConnection || $connection->getDriverName() !== 'mysql') {
            throw DapterException::configuration();
        }
        parent::__construct($source, $options);
    }
}
