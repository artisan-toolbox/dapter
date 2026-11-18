<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final readonly class CountQueries
{
    /** @param Builder<Model> $total
     * @param Builder<Model> $filtered */
    public function __construct(public Builder $total, public Builder $filtered) {}
}
