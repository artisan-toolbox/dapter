<?php

declare(strict_types=1);

namespace App\DataSources;

use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\EloquentDataSource;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

final class ComposedFixture
{
    public static ?StoreOptions $options = null;

    /** @var Builder<ComposedRecord>|null */
    public static ?Builder $builder = null;

    public static string $connection = 'testing';

    public static int $reads = 0;

    public static int $constructed = 0;

    public static int $applied = 0;

    public static int $counts = 0;

    /** @var list<array<string,int|string>> */
    public static array $rows = [];
}

final class ComposedArray extends CollectionDataSource implements WithOptions
{
    public function options(): StoreOptions
    {
        return ComposedFixture::$options;
    }

    public function source(): array
    {
        ComposedFixture::$reads++;

        return array_values(array_filter(ComposedFixture::$rows, static fn (array $row): bool => $row['tenant'] === 1));
    }
}

/** @extends EloquentDataSource<ComposedRecord> */
final class ComposedSql extends EloquentDataSource implements WithOptions
{
    public function options(): StoreOptions
    {
        return ComposedFixture::$options;
    }

    public function source(): Builder
    {
        ComposedFixture::$reads++;

        return ComposedFixture::$builder ?? (new ComposedRecord)->setConnection(ComposedFixture::$connection)->newQuery()->where('tenant', 1);
    }
}

#[Unguarded]
#[Table(name: 'dapter_composed_records')]
#[WithoutTimestamps]
final class ComposedRecord extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /** @return HasMany<ComposedLine,$this> */
    public function lines(): HasMany
    {
        return $this->hasMany(ComposedLine::class, 'record_id');
    }
}

#[Unguarded]
#[Table(name: 'dapter_composed_lines')]
#[WithoutTimestamps]
final class ComposedLine extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;
}

enum ComposedStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
}

final class ComposedStatusFilter
{
    public function __construct()
    {
        ComposedFixture::$constructed++;
    }

    /**
     * @param  Collection<array-key,mixed>|Builder<ComposedRecord>  $source
     * @return Collection<array-key,mixed>|Builder<ComposedRecord>
     */
    public function __invoke(Collection|Builder $source, ComposedStatus $status): Collection|Builder
    {
        ComposedFixture::$applied++;

        return $source->where('status', $status->value);
    }
}

final class ComposedManyFilter
{
    public function __construct()
    {
        ComposedFixture::$constructed++;
    }

    /**
     * @param  Collection<array-key,mixed>|Builder<ComposedRecord>  $source
     * @param  list<ComposedStatus>  $statuses
     * @return Collection<array-key,mixed>|Builder<ComposedRecord>
     */
    public function __invoke(Collection|Builder $source, array $statuses): Collection|Builder
    {
        ComposedFixture::$applied++;

        return $source->whereIn('status', array_map(static fn (ComposedStatus $status): string => $status->value, $statuses));
    }
}
