<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum OptionKey: string
{
    case Resource = 'resource';
    case Broadcasting = 'broadcasting';
    case Driver = 'driver';
    case Paging = 'paging';
    case ExactCounts = 'exact_counts';
    case StrictLiteralSearch = 'strict_literal_search';
    case Size = 'size';
    case Sizes = 'sizes';
    case Guard = 'guard';
    case Access = 'access';
    case Authorization = 'authorization';
}
