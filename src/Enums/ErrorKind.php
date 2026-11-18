<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum ErrorKind: string
{
    case CursorExpired = 'cursor_expired';
    case Access = 'access';
    case Validation = 'validation';
    case UnsupportedOperation = 'unsupported_operation';
    case Server = 'server';
}
