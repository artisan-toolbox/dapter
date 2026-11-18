<?php

declare(strict_types=1);

namespace App\DataSources;

use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\EloquentDataSource;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final class NativeBackendFixture
{
    public static int $reads = 0;

    public static string $connection = 'testing';

    public static bool $managed = true;

    public static ?StoreOptions $settings = null;

    /** @var list<array<string, mixed>> */
    public static array $rows = [];

    /** @var Builder<NativeBackendRecord>|null */
    public static ?Builder $builder = null;

    public static function options(): StoreOptions
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $options = StoreOptions::make()->publicAccess()->pageSize(5)->pageSizeOptions([5, 10, 100]);

        if (! self::$managed) {
            return $options;
        }

        return $options
            ->searchableField('label', [MatchType::Contains, MatchType::Equals, MatchType::StartsWith, MatchType::EndsWith], target: 'name')
            ->searchableField('unicode_name', [MatchType::Contains, MatchType::Equals, MatchType::StartsWith, MatchType::EndsWith])
            ->searchableField('quantity', [MatchType::Equals], valueType: ValueType::Integer)
            ->orderableField('quantity', valueType: ValueType::Integer)
            ->orderableField('rank', valueType: ValueType::Integer)
            ->filterableField('quantity', 'quantity', [0, 1, null])
            ->filterableField('code', 'code', ['0', '00', null])
            ->filterableField('enabled', 'enabled', [false, true, null])
            ->filterableField('status', 'status', ['active', 'inactive', null]);
    }

    /** @return list<array<string, mixed>> */
    public static function records(): array
    {
        $rows = [];

        foreach (range(1, 101) as $id) {
            $rows[] = [
                'id' => $id,
                'tenant' => $id === 101 ? 2 : 1,
                'name' => match ($id) {
                    1 => 'Tools %_\\! kit',
                    2 => 'Tools xxZ kit',
                    3, 4 => 'Tied',
                    default => sprintf('Record %03d', $id),
                },
                'rank' => $id <= 2 ? 0 : 1,
                'unicode_name' => match ($id) {
                    1 => 'Café',
                    2 => "Cafe\u{0301}",
                    3 => 'CAFÉ',
                    4 => 'cafe',
                    5 => 'CAFE',
                    default => 'Unrelated',
                },
                'quantity' => $id === 1 ? 0 : ($id === 2 ? null : 1),
                'code' => $id === 1 ? '0' : ($id === 2 ? null : '00'),
                'enabled' => $id === 1 ? false : ($id === 2 ? null : true),
                'status' => $id === 1 ? 'active' : ($id === 2 ? null : 'inactive'),
                'secret' => 'Server-only note '.$id,
            ];
        }

        return $rows;
    }

    /** @return list<NativeBackendRow> */
    public static function visibleRows(): array
    {
        return array_values(array_map(
            static fn (array $row): NativeBackendRow => new NativeBackendRow($row),
            array_filter(self::$rows, static fn (array $row): bool => $row['tenant'] === 1),
        ));
    }
}

/** @implements Arrayable<string, mixed> */
final readonly class NativeBackendRow implements Arrayable
{
    /** @param array<string, mixed> $attributes */
    public function __construct(private array $attributes) {}

    public function toArray(): array
    {
        return array_diff_key($this->attributes, ['secret' => true]);
    }
}

final class NativeArrayRecords extends CollectionDataSource implements WithOptions
{
    public function options(): StoreOptions
    {
        return NativeBackendFixture::options();
    }

    /** @return list<NativeBackendRow> */
    public function source(): array
    {
        NativeBackendFixture::$reads++;

        return NativeBackendFixture::visibleRows();
    }
}

final class NativeCollectionRecords extends CollectionDataSource implements WithOptions
{
    public function options(): StoreOptions
    {
        return NativeBackendFixture::options();
    }

    /** @return Collection<int, NativeBackendRow> */
    public function source(): Collection
    {
        NativeBackendFixture::$reads++;

        return collect(NativeBackendFixture::visibleRows());
    }
}

/** @extends EloquentDataSource<NativeBackendRecord> */
final class NativeSqlRecords extends EloquentDataSource implements WithOptions
{
    public function options(): StoreOptions
    {
        return NativeBackendFixture::options();
    }

    /** @return Builder<NativeBackendRecord> */
    public function source(): Builder
    {
        NativeBackendFixture::$reads++;

        return NativeBackendFixture::$builder ?? NativeBackendRecord::on(NativeBackendFixture::$connection)
            ->where('id', '<=', 50)->orWhere('id', '>', 50);
    }
}

#[Unguarded]
#[Hidden(['secret'])]
#[Table(name: 'dapter_native_records')]
#[WithoutTimestamps]
final class NativeBackendRecord extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['id' => 'integer', 'tenant' => 'integer', 'rank' => 'integer', 'quantity' => 'integer', 'enabled' => 'boolean'];
    }
}
