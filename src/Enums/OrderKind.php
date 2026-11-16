<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum OrderKind: string
{
    case Default = 'default';
    case Source = 'source';
}
