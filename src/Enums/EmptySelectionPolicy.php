<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum EmptySelectionPolicy: string
{
    case Clear = 'clear';
    case Reject = 'reject';
}
