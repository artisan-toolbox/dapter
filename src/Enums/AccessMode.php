<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum AccessMode: string
{
    case Required = 'required';
    case Public = 'public';
}
