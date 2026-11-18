<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Options\FieldAttribute;
use ArtisanToolbox\Dapter\Options\FieldOperation;
use ArtisanToolbox\Dapter\Options\FieldSetting;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class WithoutFieldSearch implements FieldAttribute
{
    public function __construct(public string $field) {}

    public function setting(): FieldSetting
    {
        return new FieldSetting($this->field, FieldOperation::Search, null, null);
    }
}
