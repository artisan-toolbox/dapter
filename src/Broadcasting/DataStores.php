<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use ArtisanToolbox\Dapter\DataStore;
use ArtisanToolbox\Dapter\Http\DapterException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;

final class DataStores
{
    public function recipient(string $source, Request $request): RecipientReference
    {
        return resolve(RecipientContexts::class)->recipient($source, $request);
    }

    public function forRecipient(RecipientReference $recipient, string $connection): DataStore
    {
        if (trim($connection) === '') {
            throw DapterException::configuration();
        }
        resolve(DatabaseManager::class)->connection($connection);

        return new DataStore($recipient, $connection);
    }
}
