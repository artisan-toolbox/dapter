<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers\Comparisons;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/** @internal */
final readonly class VectorExpression implements Expression
{
    public function __construct(private string $column, private string $schema) {}

    public function getValue(Grammar $grammar): string
    {
        $schema = '"'.str_replace('"', '""', $this->schema).'"';

        return '('.$grammar->wrap($this->column).' OPERATOR('.$schema.'.<->) ?::'.$schema.'.vector) <= ?::pg_catalog.float8';
    }
}
