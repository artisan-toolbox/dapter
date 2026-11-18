<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum ActionMethod: string
{
    case Post = 'post';
    case Put = 'put';
    case Patch = 'patch';
    case Delete = 'delete';
}
