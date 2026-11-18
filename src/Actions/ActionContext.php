<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

final readonly class ActionContext
{
    /** @param Builder<Model>|Collection<array-key,mixed> $mandatorySource */
    public function __construct(private mixed $resolvedRecord, private Builder|Collection $mandatorySource) {}

    public function record(): mixed
    {
        return $this->resolvedRecord;
    }

    /** @return Builder<Model>|Collection<array-key,mixed> */
    public function source(): Builder|Collection
    {
        return clone $this->mandatorySource;
    }
}
