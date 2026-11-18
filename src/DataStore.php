<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter;

use ArtisanToolbox\Dapter\Broadcasting\CommitBoundary;
use ArtisanToolbox\Dapter\Broadcasting\PublishUpdates;
use ArtisanToolbox\Dapter\Broadcasting\RecipientReference;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Support\RowIdentity;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Support\Str;

final readonly class DataStore
{
    /** @internal Obtain a context-bound store through DataStores::forRecipient(). */
    public function __construct(private RecipientReference $recipient, private string $connection) {}

    /** @param int|string|list<int|string> $identified */
    public function update(mixed $identified): void
    {
        $ids = RowIdentity::batch($identified);

        if ($ids === []) {
            return;
        }
        $connection = config('dapter.broadcasting.queue_connection');
        $queue = config('dapter.broadcasting.queue');

        if ($connection !== null && (! is_string($connection) || $connection === '' || ! is_array(config('queue.connections.'.$connection))) || $queue !== null && (! is_string($queue) || trim($queue) === '' || ! preg_match('//u', $queue))) {
            throw DapterException::configuration();
        }
        $events = array_map(static fn (int|string $id): string => (string) Str::uuid(), $ids);
        $command = resolve(StringEncrypter::class)->encryptString(json_encode(['recipient' => $this->recipient->encrypted, 'ids' => $ids, 'events' => $events], JSON_THROW_ON_ERROR));
        $job = (new PublishUpdates($command))->onConnection($connection)->onQueue($queue);
        (new CommitBoundary)->after($this->connection, static fn () => resolve(Dispatcher::class)->dispatch($job));
    }
}
