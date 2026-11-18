<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

/** @internal */
final readonly class PreparedRows
{
    /** @param list<mixed> $data
     * @param array<string,mixed> $records */
    public function __construct(public array $data, public array $records) {}
}
