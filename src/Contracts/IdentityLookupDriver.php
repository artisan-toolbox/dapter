<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Contracts;

interface IdentityLookupDriver
{
    /** @param list<int|string> $publicIds
     * @return list<mixed> */
    public function identifiedRows(RowIdentityProvider $identity, array $publicIds): array;
}
