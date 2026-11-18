<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

interface PublicRowProjection
{
    public function project(mixed $rawRow): mixed;
}
