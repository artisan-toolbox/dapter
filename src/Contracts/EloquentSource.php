<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @template TModel of Model */
interface EloquentSource extends DataSource
{
    /** @return Builder<TModel> */
    public function source(): Builder;
}
