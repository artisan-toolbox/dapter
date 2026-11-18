<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

interface RowRevisionProvider
{
    public function revision(mixed $rawRow): string;
}
