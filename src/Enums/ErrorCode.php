<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Enums;

enum ErrorCode: string
{
    case InvalidCursor = 'invalid_cursor';
    case CursorExpired = 'cursor_expired';
    case AccessDenied = 'access_denied';
    case AuthenticationRequired = 'authentication_required';
    case InvalidRequest = 'invalid_request';
    case InvalidSource = 'invalid_source';
    case MalformedJson = 'malformed_json';
    case SourceNotFound = 'source_not_found';
    case UnsupportedOperation = 'unsupported_operation';
}
