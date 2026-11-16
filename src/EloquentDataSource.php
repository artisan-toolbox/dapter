<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter;

use ArtisanToolbox\Dapter\Contracts\EloquentSource;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 *
 * @implements EloquentSource<TModel>
 */
abstract class EloquentDataSource implements EloquentSource {}
