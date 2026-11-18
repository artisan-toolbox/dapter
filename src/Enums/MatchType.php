<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum MatchType: string
{
    case Contains = 'contains';
    case FullText = 'full_text';
    case Vector = 'vector';
    case Equals = 'equals';
    case StartsWith = 'starts_with';
    case EndsWith = 'ends_with';
}
