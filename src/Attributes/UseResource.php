<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Attributes;

use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Options\AttributeSetting;
use Attribute;
use Illuminate\Http\Resources\Json\JsonResource;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class UseResource implements AttributeSetting
{
    /** @param class-string<JsonResource> $resourceClass */
    public function __construct(public string $resourceClass) {}

    public function key(): OptionKey
    {
        return OptionKey::Resource;
    }

    public function value(): mixed
    {
        return $this->resourceClass;
    }
}
