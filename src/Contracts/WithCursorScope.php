<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

interface WithCursorScope
{
    public function cursorScope(): string;
}
