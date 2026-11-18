<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

final readonly class SimpleRequest
{
    public function __construct(public int $current, public int $perPage) {}

    /** @return array{mode:string,current:int,per_page:int} */
    public function toArray(): array
    {
        return ['mode' => 'simple', 'current' => $this->current, 'per_page' => $this->perPage];
    }
}
