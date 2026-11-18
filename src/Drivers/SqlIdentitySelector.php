<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use ArtisanToolbox\Dapter\Http\DapterException;

final readonly class SqlIdentitySelector
{
    public function __construct(public string $column, public string $attribute)
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*(?:\.[A-Za-z_][A-Za-z_0-9]*)?\z/D', $column) || ! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\z/D', $attribute)) {
            throw DapterException::configuration();
        }
    }
}
