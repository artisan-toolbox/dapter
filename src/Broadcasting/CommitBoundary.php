<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use ArtisanToolbox\Dapter\Http\DapterException;
use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\DatabaseTransactionRecord;

/** @internal */
final class CommitBoundary
{
    public function after(string $connection, Closure $callback): void
    {
        $database = resolve(DatabaseManager::class)->connection($connection);

        if (! app()->bound('db.transactions')) {
            throw DapterException::configuration();
        }
        $manager = resolve('db.transactions');

        if ($database->transactionLevel() === 0) {
            $callback();

            return;
        }
        $record = $manager->getPendingTransactions()->filter(static fn (DatabaseTransactionRecord $record): bool => $record->connection === $database->getName())->sortByDesc('level')->first();

        if ($record === null || $record->level !== $database->transactionLevel()) {
            throw DapterException::configuration();
        }
        $record->addCallback($callback);
    }
}
