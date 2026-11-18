<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum SortType: string
{
    case Native = 'native';
    case Regular = 'regular';
    case String = 'string';
    case Natural = 'natural';
    case Numeric = 'numeric';
    case Locale = 'locale';
}
