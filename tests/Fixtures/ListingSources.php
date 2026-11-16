<?php

declare(strict_types=1);

namespace App\DataSources;

use ArtisanToolbox\Dapter\Attributes\Authorize;
use ArtisanToolbox\Dapter\Attributes\Guard;
use ArtisanToolbox\Dapter\Attributes\PageSize;
use ArtisanToolbox\Dapter\Attributes\PageSizeOptions;
use ArtisanToolbox\Dapter\Attributes\Paging;
use ArtisanToolbox\Dapter\Attributes\PublicAccess;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\CollectionSource;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\EloquentDataSource;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class PublicRecords extends CollectionDataSource implements WithOptions
{
    public static int $reads = 0;

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess();
    }

    public function source(): array
    {
        self::$reads++;

        return [4 => ['id' => 1, 'name' => 'Notebook'], 9 => ['id' => 2, 'name' => 'Pencil']];
    }
}

class OptionRecords extends CollectionDataSource implements WithOptions
{
    public static ?StoreOptions $settings = null;

    public static int $reads = 0;

    public static int $hooks = 0;

    /** @var array<array-key, mixed> */
    public static array $rows = [];

    public function options(): StoreOptions
    {
        self::$hooks++;

        return self::$settings ?? StoreOptions::make();
    }

    public function source(): array
    {
        self::$reads++;

        return self::$rows;
    }
}

final class RequiredRecords extends CollectionDataSource
{
    public function source(): array
    {
        OptionRecords::$reads++;

        return [['reader' => request()->user()?->getAuthIdentifier()]];
    }
}

final class RawRecords implements CollectionSource
{
    public function source(): array
    {
        return [['name' => 'Raw contract']];
    }
}

#[Paging(PagingMode::Offset)]
#[PageSize(5)]
#[PageSizeOptions([5, 25, 5])]
#[Guard('web')]
#[PublicAccess]
class AttributeRecords extends OptionRecords {}

#[PageSize(25)]
final class ChildAttributeRecords extends AttributeRecords {}

#[PublicAccess]
#[Authorize('read-records', ['fixture'])]
final class AuthorizedAttributeRecords extends CollectionDataSource
{
    public function source(): array
    {
        OptionRecords::$reads++;

        return [];
    }
}

#[PageSize(5)]
#[PageSize(10)]
final class DuplicateAttributes extends CollectionDataSource
{
    public function source(): array
    {
        OptionRecords::$reads++;

        return [];
    }
}

final class UndeclaredOptions extends CollectionDataSource
{
    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess();
    }

    public function source(): array
    {
        OptionRecords::$reads++;

        return [];
    }
}

/** @extends EloquentDataSource<FixtureRecord> */
final class SqlRecords extends EloquentDataSource implements WithOptions
{
    /** @var Builder<FixtureRecord>|null */
    public static ?Builder $builder = null;

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess()->pageSize(5);
    }

    /** @return Builder<FixtureRecord> */
    public function source(): Builder
    {
        OptionRecords::$reads++;

        return self::$builder ?? FixtureRecord::query();
    }
}

#[Unguarded]
#[Hidden(['secret'])]
#[Table(name: 'fixture_records')]
#[WithoutTimestamps]
final class FixtureRecord extends Model {}

final class FreshRecords extends CollectionDataSource implements WithOptions
{
    public static int $constructed = 0;

    private int $generation;

    private int $sourceCalls = 0;

    public function __construct()
    {
        $this->generation = ++self::$constructed;
    }

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess();
    }

    public function source(): array
    {
        return [['generation' => $this->generation, 'source_calls' => ++$this->sourceCalls]];
    }
}

final class ReaderRecords extends CollectionDataSource implements WithOptions
{
    public function options(): StoreOptions
    {
        return StoreOptions::make()->guard('alternate');
    }

    public function source(): array
    {
        return [new ReaderRow(request()->user()?->getAuthIdentifier())];
    }
}

/** @implements Arrayable<string, mixed> */
final readonly class ReaderRow implements Arrayable
{
    public function __construct(private mixed $sourceReader) {}

    public function toArray(): array
    {
        return ['source_reader' => $this->sourceReader, 'row' => ['reader' => request()->user()?->getAuthIdentifier()]];
    }
}

#[PublicAccess]
final class PrivateOptions extends CollectionDataSource
{
    public function source(): array
    {
        return $this->options();
    }

    /** @return list<array{name: string}> */
    private function options(): array
    {
        return [['name' => 'No public options hook']];
    }
}

final class UnrelatedSourceHelper
{
    public static int $constructions = 0;

    public function __construct()
    {
        self::$constructions++;
    }
}

#[PublicAccess]
final class ScopedRecords extends CollectionDataSource
{
    public function source(): array
    {
        $records = [
            ['name' => 'North notebook', 'studio' => 'studio-north', 'private_note' => 'Fixture-only note'],
            ['name' => 'South sketchbook', 'studio' => 'studio-south', 'private_note' => 'Another fixture note'],
        ];

        return collect($records)->where('studio', 'studio-north')
            ->map(fn (array $record): ProjectedRecord => new ProjectedRecord($record))->values()->all();
    }
}

/** @implements Arrayable<string, string> */
final readonly class ProjectedRecord implements Arrayable
{
    /** @param array{name: string, studio: string, private_note: string} $record */
    public function __construct(private array $record) {}

    public function toArray(): array
    {
        return ['name' => $this->record['name'], 'studio' => $this->record['studio']];
    }
}
