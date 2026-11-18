<?php

declare(strict_types=1);

namespace App\DataSources;

use ArtisanToolbox\Dapter\Attributes\ExactCounts;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\WithCursorScope;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\StoreOptions;

class KernelPagingRecords extends CollectionDataSource implements WithCursorScope, WithOptions
{
    public static ?StoreOptions $settings = null;

    public static int $reads = 0;

    public static int $scopes = 0;

    public static string $scope = 'public-test-v1';

    public function options(): StoreOptions
    {
        return self::$settings ?? StoreOptions::make()->publicAccess();
    }

    public function cursorScope(): string
    {
        self::$scopes++;

        return self::$scope;
    }

    /** @return list<array{id:int,title:string}> */
    public function source(): array
    {
        self::$reads++;

        return [['id' => 1, 'title' => 'Notebook'], ['id' => 2, 'title' => 'Pencil'], ['id' => 3, 'title' => 'Eraser']];
    }
}

#[ExactCounts]
class KernelCountedRecords extends KernelPagingRecords {}

#[ExactCounts(false)]
class KernelUncountedRecords extends KernelCountedRecords {}

#[ExactCounts]
#[ExactCounts(false)]
class KernelDuplicateCounts extends KernelPagingRecords {}

class KernelNoScopeRecords extends CollectionDataSource implements WithOptions
{
    public function options(): StoreOptions
    {
        return KernelPagingRecords::$settings ?? StoreOptions::make()->publicAccess();
    }

    /** @return list<array{id:int}> */
    public function source(): array
    {
        KernelPagingRecords::$reads++;

        return [['id' => 1]];
    }
}
