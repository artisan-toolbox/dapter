<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

/** @internal */
enum FieldOperation: string
{
    case Search = 'search';
    case Order = 'order';
    case Filter = 'filter';
}
