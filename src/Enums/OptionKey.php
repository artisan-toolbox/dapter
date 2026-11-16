<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum OptionKey: string
{
    case Paging = 'paging';
    case Size = 'size';
    case Sizes = 'sizes';
    case Guard = 'guard';
    case Access = 'access';
    case Authorization = 'authorization';
}
