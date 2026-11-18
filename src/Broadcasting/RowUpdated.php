<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel as LaravelPrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/** @internal */
final readonly class RowUpdated implements ShouldBroadcastNow
{
    public int $version;

    public string $eventId;

    public string $source;

    /** @var array{type:string,name:string,event:string} */
    public array $channel;

    /** @var list<mixed> */
    public array $data;

    /** @var array<string,mixed> */
    public array $records;

    /** @param array<string,mixed> $payload */
    public function __construct(array $payload)
    {
        $this->version = $payload['version'];
        $this->eventId = $payload['eventId'];
        $this->source = $payload['source'];
        $this->channel = $payload['channel'];
        $this->data = $payload['data'];
        $this->records = $payload['records'];
    }

    public function broadcastAs(): string
    {
        return 'dapter.updated';
    }

    /** @return list<Channel> */
    public function broadcastOn(): array
    {
        $channel = $this->channel;

        return [$channel['type'] === 'private' ? new LaravelPrivateChannel($channel['name']) : new Channel($channel['name'])];
    }
}
