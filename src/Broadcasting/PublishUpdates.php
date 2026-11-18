<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use ArtisanToolbox\Dapter\Contracts\IdentityLookupDriver;
use ArtisanToolbox\Dapter\Drivers\DriverFactory;
use ArtisanToolbox\Dapter\Drivers\EloquentStoreDriver;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\RowSerializer;
use ArtisanToolbox\Dapter\Support\FieldIdentity;
use ArtisanToolbox\Dapter\Support\RowIdentity;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;

/** @internal */
final class PublishUpdates implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $command) {}

    public function handle(): void
    {
        $command = json_decode(resolve(StringEncrypter::class)->decryptString($this->command), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($command) || array_keys($command) !== ['recipient', 'ids', 'events'] || ! is_string($command['recipient']) || ! is_array($command['ids']) || RowIdentity::batch($command['ids']) !== $command['ids'] || ! is_array($command['events']) || ! array_is_list($command['events']) || count($command['events']) !== count($command['ids'])) {
            throw DapterException::configuration();
        }
        foreach ($command['events'] as $event) {
            if (! is_string($event) || ! preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $event)) {
                throw DapterException::configuration();
            }
        }
        $read = resolve(RecipientContexts::class)->delivery(new RecipientReference($command['recipient']));

        if ($read === null) {
            return;
        }
        $driver = (new DriverFactory)->make($read->restored->source, $read->options);

        if (! $driver instanceof IdentityLookupDriver) {
            throw DapterException::configuration();
        }
        $broadcast = $read->options->broadcasting;
        $identity = $broadcast->identity !== null ? resolve($broadcast->identity) : ($driver instanceof EloquentStoreDriver ? $driver->rowIdentity($broadcast->identifierField) : new FieldIdentity($broadcast->identifierField));
        $rows = $driver->identifiedRows($identity, $command['ids']);
        $prepared = (new RowSerializer)->prepare($rows, $read->options, $read->restored->request, $read->projection);
        $events = [];
        foreach ($command['ids'] as $index => $id) {
            $events[RowIdentity::key($id)] = $command['events'][$index];
        }
        $payloads = [];
        foreach ($prepared->data as $index => $row) {
            $record = $prepared->records['rows'][$index];
            $event = $events[RowIdentity::key($record['id'])] ?? throw DapterException::configuration();
            $records = [...$prepared->records, 'rows' => [$record]];
            $payload = ['version' => 1, 'eventId' => $event, 'source' => $read->ticket['source'], 'channel' => $read->channel, 'data' => [$row], 'records' => $records];
            $payloads[] = UpdateEnvelope::parse($payload);
        }
        foreach ($payloads as $payload) {
            resolve(Dispatcher::class)->dispatch(new RowUpdated($payload));
        }
    }
}
