<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Broadcasting\PrivateChannel;
use ArtisanToolbox\Dapter\Broadcasting\PublicChannel;
use ArtisanToolbox\Dapter\Contracts\ChannelContextResolver;
use ArtisanToolbox\Dapter\Contracts\RowIdentityProvider;
use ArtisanToolbox\Dapter\Contracts\RowRevisionProvider;
use ArtisanToolbox\Dapter\Http\DapterException;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use ReflectionClass;

/** @internal */
final class ProjectionOptions
{
    /** @param array<string,mixed> $values
     * @return array{?string,?BroadcastOptions} */
    public static function parse(array $values): array
    {
        $resource = $values['resource'] ?? null;

        if ($resource !== null && (! is_string($resource) || ! is_subclass_of($resource, JsonResource::class) || is_a($resource, ResourceCollection::class, true) || ! (new ReflectionClass($resource))->isInstantiable())) {
            throw DapterException::configuration();
        }
        $broadcast = $values['broadcasting'] ?? null;

        if ($broadcast !== null) {
            if (! $broadcast instanceof BroadcastOptions || $broadcast->identity !== null && $broadcast->identifierField !== null || $broadcast->identifierField !== null && ! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\z/D', $broadcast->identifierField)) {
                throw DapterException::configuration();
            }
            foreach ([[$broadcast->identity, RowIdentityProvider::class], [$broadcast->revisions, RowRevisionProvider::class]] as [$class, $contract]) {
                if ($class !== null && (! is_subclass_of($class, $contract) || ! (new ReflectionClass($class))->isInstantiable())) {
                    throw DapterException::configuration();
                }
            }
            $channel = $broadcast->channel;

            if ($channel === null) {
                $context = $values['broadcast_context'] ?? null;

                if (! is_string($context)) {
                    throw DapterException::configuration();
                }
                $channel = new PrivateChannel('dapter.'.$values['guard'].'.{source}.{context}', $context);
            }

            if ($channel instanceof PrivateChannel && (! is_subclass_of($channel->context, ChannelContextResolver::class) || ! (new ReflectionClass($channel->context))->isInstantiable() || $channel->authorize === '') || $channel instanceof PublicChannel && $resource !== null) {
                throw DapterException::configuration();
            }
            $broadcast = new BroadcastOptions($broadcast->identifierField, $channel, $broadcast->revisions, $broadcast->identity);
        }

        return [$resource, $broadcast];
    }
}
