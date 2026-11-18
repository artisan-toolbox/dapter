<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\EmptySelectionPolicy;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\FieldAttribute;
use ArtisanToolbox\Dapter\Options\FieldOperation;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\FieldSetting;
use ArtisanToolbox\Dapter\Options\FilterDefinition;
use Attribute;
use UnitEnum;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class FilterableField implements FieldAttribute
{
    /** @param array<array-key,string|int|float|bool|null|UnitEnum>|string|UnitEnum $values */
    public function __construct(public string $field, public string $id, public array|string|UnitEnum $values, public bool $multiple = false, public EmptySelectionPolicy $empty = EmptySelectionPolicy::Clear, public ?string $target = null, public ?ValueType $valueType = null, public ?FieldOptions $options = null) {}

    public function setting(): FieldSetting
    {
        return new FieldSetting($this->field, FieldOperation::Filter, $this->id, new FilterDefinition($this->values, $this->multiple, $this->empty, $this->target, $this->valueType, $this->options));
    }
}
