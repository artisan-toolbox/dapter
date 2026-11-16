<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum ErrorKind: string
{
    case Access = 'access';
    case Validation = 'validation';
    case UnsupportedOperation = 'unsupported_operation';
    case Server = 'server';
}
