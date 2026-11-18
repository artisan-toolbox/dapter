<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

final readonly class CursorRequest
{
    public function __construct(public ?string $cursor, public int $perPage) {}

    /** @return array{mode:string,cursor:?string,per_page:int} */
    public function toArray(): array
    {
        return ['mode' => 'cursor', 'cursor' => $this->cursor, 'per_page' => $this->perPage];
    }
}
