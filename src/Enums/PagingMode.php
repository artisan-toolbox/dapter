<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum PagingMode: string
{
    case Offset = 'offset';
    case Simple = 'simple';
    case Cursor = 'cursor';
}
