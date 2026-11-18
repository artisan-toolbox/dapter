<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\FieldAttribute;
use ArtisanToolbox\Dapter\Options\FieldOperation;
use ArtisanToolbox\Dapter\Options\FieldOptions;
use ArtisanToolbox\Dapter\Options\FieldSetting;
use ArtisanToolbox\Dapter\Options\SearchDefinition;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class SearchableField implements FieldAttribute
{
    /** @param list<MatchType> $matchTypes */
    public function __construct(public string $field, public array $matchTypes = [MatchType::Contains], public ?string $target = null, public ValueType $valueType = ValueType::String, public ?FieldOptions $options = null) {}

    public function setting(): FieldSetting
    {
        return new FieldSetting($this->field, FieldOperation::Search, null, new SearchDefinition($this->matchTypes, $this->target, $this->valueType, $this->options));
    }
}
