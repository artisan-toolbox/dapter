<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Broadcasting\PrivateChannel;
use ArtisanToolbox\Dapter\Broadcasting\PublicChannel;
use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Options\AttributeSetting;
use ArtisanToolbox\Dapter\Options\BroadcastOptions;
use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class BroadcastUpdatable implements AttributeSetting
{
    public function __construct(public ?string $identifierField = null, public PrivateChannel|PublicChannel|null $channel = null, public ?string $revisions = null, public ?string $identity = null) {}

    public function key(): OptionKey
    {
        return OptionKey::Broadcasting;
    }

    public function value(): mixed
    {
        return new BroadcastOptions($this->identifierField, $this->channel, $this->revisions, $this->identity);
    }
}
