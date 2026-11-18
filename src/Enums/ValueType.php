<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum ValueType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Number = 'number';
    case Boolean = 'boolean';
}
