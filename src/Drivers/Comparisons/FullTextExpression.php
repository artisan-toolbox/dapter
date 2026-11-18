<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers\Comparisons;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/** @internal */
final readonly class FullTextExpression implements Expression
{
    public function __construct(private string $column) {}

    public function getValue(Grammar $grammar): string
    {
        return 'pg_catalog.to_tsvector(?::pg_catalog.regconfig, coalesce('.$grammar->wrap($this->column).", '')) @@ pg_catalog.plainto_tsquery(?::pg_catalog.regconfig, ?)";
    }
}
