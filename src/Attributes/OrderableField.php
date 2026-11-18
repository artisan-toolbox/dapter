<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\FieldAttribute;
use ArtisanToolbox\Dapter\Options\FieldOperation;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\FieldSetting;
use ArtisanToolbox\Dapter\Options\OrderDefinition;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class OrderableField implements FieldAttribute
{
    /**
     * @param  list<SortType>  $sortTypes
     * @param  list<SortDirection>  $directions
     */
    public function __construct(public string $field, public array $sortTypes = [SortType::Native], public array $directions = [SortDirection::Asc, SortDirection::Desc], public ?string $target = null, public ?ValueType $valueType = null, public ?FieldOptions $options = null) {}

    public function setting(): FieldSetting
    {
        return new FieldSetting($this->field, FieldOperation::Order, null, new OrderDefinition($this->sortTypes, $this->directions, $this->target, $this->valueType, $this->options));
    }
}
