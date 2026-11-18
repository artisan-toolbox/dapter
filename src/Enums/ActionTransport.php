<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum ActionTransport: string
{
    case Visit = 'visit';
    case Http = 'http';
}
