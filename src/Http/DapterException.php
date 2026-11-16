<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use ArtisanToolbox\Dapter\Enums\ErrorCode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use RuntimeException;

final class DapterException extends RuntimeException
{
    public readonly string $errorCode;

    public function __construct(public readonly ErrorKind $kind, string|ErrorCode $errorCode, public readonly int $status)
    {
        $this->errorCode = $errorCode instanceof ErrorCode ? $errorCode->value : $errorCode;
        parent::__construct('The data request could not be completed.');
    }

    public static function configuration(): self
    {
        return new self(ErrorKind::Server, ErrorCode::InvalidSource, 500);
    }

    public static function validation(): self
    {
        return new self(ErrorKind::Validation, ErrorCode::InvalidRequest, 422);
    }

    public static function unsupported(): self
    {
        return new self(ErrorKind::UnsupportedOperation, ErrorCode::UnsupportedOperation, 422);
    }

    public static function missing(): self
    {
        return new self(ErrorKind::Access, ErrorCode::SourceNotFound, 404);
    }
}
