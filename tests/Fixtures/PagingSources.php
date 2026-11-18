<?php

declare(strict_types=1);

namespace App\DataSources;

use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\WithCursorScope;
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

final class PagingFixture
{
    public static ?StoreOptions $options = null;

    /** @var list<array<string,int|string|null>> */
    public static array $rows = [];

    /** @var Builder<PagingRecord>|null */
    public static ?Builder $builder = null;

    public static string $connection = 'testing';

    public static int $counts = 0;

    public static string $scope = 'paging-fixture-v1';
}

final class PagingArray extends CollectionDataSource implements WithCursorScope, WithOptions
{
    public function options(): StoreOptions
    {
        return PagingFixture::$options;
    }

    public function source(): array
    {
        return array_values(array_filter(PagingFixture::$rows, static fn (array $row): bool => $row['tenant'] === 1));
    }

    public function cursorScope(): string
    {
        return PagingFixture::$scope;
    }
}

/** @extends EloquentDataSource<PagingRecord> */
final class PagingSql extends EloquentDataSource implements WithCursorScope, WithOptions
{
    public function options(): StoreOptions
    {
        return PagingFixture::$options;
    }

    public function source(): Builder
    {
        return PagingFixture::$builder ?? (new PagingRecord)->setConnection(PagingFixture::$connection)->newQuery()->where('tenant', 1);
    }

    public function cursorScope(): string
    {
        return PagingFixture::$scope;
    }
}

#[Unguarded]
#[Table(name: 'dapter_phase4_records')]
#[WithoutTimestamps]
final class PagingRecord extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;
}
