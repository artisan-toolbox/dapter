<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

use Illuminate\Support\Collection;

interface CollectionSource extends DataSource
{
    /** @return Collection<array-key, mixed>|array<array-key, mixed> */
    public function source(): Collection|array;
}
