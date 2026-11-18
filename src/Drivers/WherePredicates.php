<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers;

use Illuminate\Database\Eloquent\Builder;

/** @internal */
final class WherePredicates
{
    /** @param Builder<*> $source */
    public static function group(Builder $source): void
    {
        $query = $source->getQuery();

        if ($query->wheres !== []) {
            $nested = $query->forNestedWhere();
            $nested->wheres = $query->wheres;
            $nested->setBindings($query->getRawBindings()['where'], 'where');
            $query->wheres = [];
            $query->setBindings([], 'where');
            $query->addNestedWhereQuery($nested);
        }
    }
}
