<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/** @internal */
final readonly class IdentityRangeExpression implements Expression
{
    public function __construct(private string $column) {}

    public function getValue(Grammar $grammar): string
    {
        $column = $grammar->wrap($this->column);

        return '('.$column.' > 9007199254740991 OR '.$column.' < -9007199254740991)';
    }
}
