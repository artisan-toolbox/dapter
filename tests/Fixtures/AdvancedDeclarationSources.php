<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Tests\Fixtures;

use ArtisanToolbox\Dapter\Attributes\GlobalFilter;
use ArtisanToolbox\Dapter\Attributes\GlobalOrder;
use ArtisanToolbox\Dapter\Attributes\GlobalSearch;
use ArtisanToolbox\Dapter\Attributes\MultiOrder;
use ArtisanToolbox\Dapter\Attributes\SearchGrouping;
use ArtisanToolbox\Dapter\Attributes\WithoutGlobalFilter;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Options\EnumBinding;
use ArtisanToolbox\Dapter\Options\HandlerOptions;
use ArtisanToolbox\Dapter\Options\SearchTarget;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Support\Collection;

enum DeclarationStatus: string
{
    case Active = 'active';
    case Pending = 'pending';
}
enum OtherDeclarationStatus: string
{
    case Active = 'active';
}
enum DeclarationFlag
{
    case Ready;
    case Done;
}
final class DeclarationFilter
{
    public static int $owners = 0;

    public function __construct()
    {
        self::$owners++;
    }

    /** @param Collection<array-key,mixed> $source
     * @return Collection<array-key,mixed>
     */
    public function __invoke(Collection $source, mixed $value): Collection
    {
        return $source;
    }
}
final class DeclarationEnumFilter
{
    /** @param Collection<array-key,mixed> $source
     * @return Collection<array-key,mixed>
     */
    public function __invoke(Collection $source, DeclarationStatus $value): Collection
    {
        return $source;
    }
}
final class DeclarationElementsFilter
{
    /** @param Collection<array-key,mixed> $source
     * @param  list<mixed>  $values
     * @return Collection<array-key,mixed>
     */
    public function __invoke(Collection $source, array $values): Collection
    {
        return $source;
    }
}
#[GlobalSearch(['name' => new SearchTarget('name')])]
#[GlobalFilter(values: DeclarationStatus::class, id: 'status', apply: DeclarationFilter::class)]
#[MultiOrder(max: 3)]
#[SearchGrouping(SearchMatch::Any, [SearchMatch::All, SearchMatch::Any])]
class AdvancedParentSource extends CollectionDataSource implements WithOptions
{
    public function source(): array
    {
        return [['id' => 1, 'name' => 'Lin']];
    }

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess();
    }

    /** @param Collection<array-key,mixed> $rows
     * @return Collection<array-key,mixed>
     */
    #[GlobalFilter(values: [DeclarationFlag::Ready], id: 'flag')]
    private function inheritedFilter(Collection $rows, DeclarationFlag $flag): Collection
    {
        return $rows;
    }

    /** @param Collection<array-key,mixed> $rows
     * @return Collection<array-key,mixed>
     */
    #[GlobalOrder(id: 'name')]
    private function inheritedOrder(Collection $rows): Collection
    {
        return $rows->sortBy('name');
    }
}
#[WithoutGlobalFilter('status')]
final class AdvancedChildSource extends AdvancedParentSource {}

final class BindingLifecycleSource extends CollectionDataSource implements WithOptions
{
    public static int $sources = 0;

    public function source(): array
    {
        self::$sources++;

        return [['id' => 1, 'name' => 'Lin']];
    }

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess()->globalFilterable('first', [1, 2], DeclarationFilter::class)->globalFilterable('status', DeclarationStatus::class, DeclarationFilter::class, multiple: true, options: new HandlerOptions(new EnumBinding(DeclarationStatus::class, elements: true)));
    }
}
