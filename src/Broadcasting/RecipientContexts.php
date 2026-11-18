<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Broadcasting;

use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\ErrorCode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use ArtisanToolbox\Dapter\Paging\StableFingerprint;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Http\Request;
use Throwable;

/** @internal */
final class RecipientContexts
{
    public function listing(string $source, Request $request, bool $producer = false): LiveRead
    {
        $definition = resolve(SourceRegistry::class)->live($source) ?? throw DapterException::configuration();
        [$reader, $capture, $key] = $this->capture($definition, $request, $producer);
        $user = $reader?->user;
        $ticket = ['source' => $source, 'guard' => $definition->guard, 'principal' => $user === null ? null : $this->principal($user->getAuthIdentifier()), 'key' => $key, 'audience' => null, 'definition' => $definition->fingerprint, 'generation' => $definition->registration->generation, 'expires' => now()->getTimestamp() + 86400];
        $read = $this->reconstruct($definition, $ticket, $reader, $capture, $producer, false);
        $this->cacheAdmission($read);

        return $read;
    }

    /** @return array{DataSource,ResolvedStoreOptions,?Authenticatable} */
    public function action(LiveDefinition $definition, Request $request): array
    {
        [$reader, $capture, $key] = $this->capture($definition, $request, false);
        $actor = $reader?->user;
        $request->setUserResolver(static fn (?string $guard = null) => $actor);
        [$restored, $options] = $this->sourceContext($definition, $key, $reader, $capture, nativeAction: true);

        return [$restored->source, $options, $actor];
    }

    /** @return array{?ChannelReader,Request,string} */
    private function capture(LiveDefinition $definition, Request $request, bool $producer): array
    {
        $selected = resolve(AuthManager::class)->guard($definition->guard)->user();
        $user = $selected === null ? null : $this->fresh($definition, $selected->getAuthIdentifier());

        if ($selected !== null && $user === null || $producer && $user === null) {
            $this->deny();
        }
        $public = $definition->broadcasting->channel instanceof PublicChannel;

        throw_if(! $public && $user === null, DapterException::class, ErrorKind::Access, ErrorCode::AuthenticationRequired, 401);
        $reader = $user === null ? null : new ChannelReader($definition->alias, $definition->guard, $user);
        $capture = clone $request;
        $capture->setUserResolver(static fn (?string $guard = null) => $public ? null : $user);
        $factory = resolve($definition->registration->contexts);
        $key = $factory->capture($capture);

        if (! is_string($key) || $key === '' || strlen($key) > 1024 || ! preg_match('//u', $key)) {
            $this->deny();
        }

        return [$reader, $capture, $key];
    }

    public function recipient(string $source, Request $request): RecipientReference
    {
        $read = $this->listing($source, $request, true);

        return new RecipientReference($this->encrypt($read->ticket));
    }

    public function delivery(RecipientReference $reference): ?LiveRead
    {
        try {
            $ticket = $this->ticket($reference->encrypted);

            return $this->restore($ticket, true);
        } catch (DapterException $exception) {
            if ($exception->kind === ErrorKind::Access) {
                return null;
            }

            throw $exception;
        }
    }

    public function admit(string $source, ?Authenticatable $selected, string $token): bool
    {
        if ($selected === null || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $token)) {
            return false;
        }
        $encrypted = resolve(CacheFactory::class)->store()->get('dapter:recipient:'.$token);

        if (! is_string($encrypted)) {
            return false;
        }

        try {
            $ticket = $this->ticket($encrypted);

            if ($ticket['source'] !== $source || $ticket['principal'] !== $selected->getAuthIdentifier()) {
                return false;
            }
            $read = $this->restore($ticket, false);

            return hash_equals($this->token($read->ticket), $token);
        } catch (DapterException) {
            return false;
        }
    }

    /** @param array<string,mixed> $ticket */
    private function restore(array $ticket, bool $producer): LiveRead
    {
        $definition = resolve(SourceRegistry::class)->live($ticket['source']);

        if ($definition === null || $ticket['guard'] !== $definition->guard || $ticket['definition'] !== $definition->fingerprint || $ticket['generation'] !== $definition->registration->generation || $ticket['expires'] <= now()->getTimestamp()) {
            $this->deny();
        }
        $user = $ticket['principal'] === null ? null : $this->fresh($definition, $ticket['principal']);

        if ($ticket['principal'] !== null && $user === null || $producer && $user === null) {
            $this->deny();
        }
        $reader = $user === null ? null : new ChannelReader($definition->alias, $definition->guard, $user);

        return $this->reconstruct($definition, $ticket, $reader, null, $producer, true);
    }

    /** @param array<string,mixed> $ticket */
    private function reconstruct(LiveDefinition $definition, array $ticket, ?ChannelReader $reader, ?Request $capture, bool $producer, bool $compare): LiveRead
    {
        $channel = $definition->broadcasting->channel;
        $public = $channel instanceof PublicChannel;
        $user = $reader?->user;

        if ($producer && $definition->registration->producerAuthorize !== null && ! resolve(Gate::class)->forUser($user)->allows($definition->registration->producerAuthorize, $reader)) {
            $this->deny();
        }

        [$restored, $options, $bound] = $this->sourceContext($definition, $ticket['key'], $reader, $capture);

        if ($compare && $ticket['audience'] !== $bound) {
            $this->deny();
        }
        $ticket['audience'] = $bound;
        $descriptor = ['type' => $public ? 'public' : 'private', 'name' => $public ? $definition->pattern : str_replace('{context}', $this->token($ticket), $definition->pattern), 'event' => '.dapter.updated'];
        $projection = $public ? resolve($definition->registration->publicProjection) : null;

        return new LiveRead($restored, $options, $descriptor, $ticket, $projection);
    }

    /** @return array{RestoredSource,ResolvedStoreOptions,list<string>} */
    private function sourceContext(LiveDefinition $definition, string $key, ?ChannelReader $reader, ?Request $capture, bool $nativeAction = false): array
    {
        $channel = $definition->broadcasting->channel;
        $public = $channel instanceof PublicChannel;
        $user = $reader?->user;

        if (! $public && $reader === null) {
            $this->deny();
        }
        $restored = resolve($definition->registration->contexts)->restore($key, $public ? null : $reader);

        if ($restored === null) {
            $this->deny();
        }

        if ($restored->request === $capture || $restored->request === resolve('request') || $definition->registration->source !== $restored->source::class) {
            throw DapterException::configuration();
        }
        $restored->request->setUserResolver(static fn (?string $guard = null) => $public ? null : $user);
        $options = (new OptionsResolver)->resolve($restored->source);
        $definition->assertOptions($options);
        $accessUser = $public && ! $nativeAction ? null : $user;

        if (($definition->access === AccessMode::Required || $options->access === AccessMode::Required) && $accessUser === null) {
            throw_if($nativeAction, DapterException::class, ErrorKind::Access, ErrorCode::AuthenticationRequired, 401);
            $this->deny();
        }
        foreach ([$definition->authorization, $options->authorization] as $gate) {
            if ($gate === null) {
                continue;
            }
            $response = resolve(Gate::class)->forUser($accessUser)->inspect($gate->ability, $gate->arguments);

            if ($nativeAction) {
                $response->authorize();
            } elseif ($response->denied()) {
                $this->deny();
            }
        }

        if ($channel instanceof PrivateChannel) {
            if ($restored->audience === null || $channel->context !== $restored->audience::class) {
                $this->deny();
            }
            $audience = $restored->audience->resolve($reader);

            if ($audience === null || ! LiveDefinition::segment($audience->projection) || $audience instanceof ScopedAudience && ! LiveDefinition::segment($audience->scope)) {
                $this->deny();
            }

            if ($channel->authorize !== null && ! resolve(Gate::class)->forUser($user)->allows($channel->authorize, $reader)) {
                $this->deny();
            }
            $bound = $audience instanceof ScopedAudience ? ['scoped', $audience->scope, $audience->projection] : ['global', $audience->projection];
        } else {
            if ($restored->audience !== null) {
                throw DapterException::configuration();
            }
            $bound = ['public', $channel->projection];
        }

        return [$restored, $options, $bound];
    }

    private function fresh(LiveDefinition $definition, mixed $identifier): ?Authenticatable
    {
        $provider = resolve(AuthManager::class)->createUserProvider(config('auth.guards.'.$definition->guard.'.provider'));

        if ($provider === null) {
            throw DapterException::configuration();
        }
        $user = $provider->retrieveById($identifier);

        return $user !== null && $user->getAuthIdentifier() === $identifier ? $user : null;
    }

    /** @param array<string,mixed> $ticket */
    private function token(array $ticket): string
    {
        $tuple = [$ticket['source'], $ticket['guard'], $ticket['principal'], hash('sha256', (string) config('app.key')), $ticket['audience'], $ticket['definition'], $ticket['generation']];

        return rtrim(strtr(base64_encode(hash_hmac('sha256', StableFingerprint::of($tuple), (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    private function cacheAdmission(LiveRead $read): void
    {
        if ($read->channel['type'] === 'private') {
            resolve(CacheFactory::class)->store()->put('dapter:recipient:'.$this->token($read->ticket), $this->encrypt($read->ticket), 86400);
        }
    }

    /** @param array<string,mixed> $ticket */
    private function encrypt(array $ticket): string
    {
        return resolve(StringEncrypter::class)->encryptString(json_encode($ticket, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function ticket(string $encrypted): array
    {
        try {
            $ticket = json_decode(resolve(StringEncrypter::class)->decryptString($encrypted), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            $this->deny();
        }

        if (! is_array($ticket) || array_keys($ticket) !== ['source', 'guard', 'principal', 'key', 'audience', 'definition', 'generation', 'expires'] || ! is_string($ticket['source']) || ! is_string($ticket['guard']) || ! is_string($ticket['key']) || $ticket['key'] === '' || strlen($ticket['key']) > 1024 || ! preg_match('//u', $ticket['key']) || ! is_array($ticket['audience']) || ! array_is_list($ticket['audience']) || ! is_string($ticket['definition']) || ! is_string($ticket['generation']) || ! is_int($ticket['expires'])) {
            $this->deny();
        }

        if ($ticket['principal'] !== null) {
            $this->principal($ticket['principal']);
        }

        return $ticket;
    }

    private function principal(mixed $value): int|string
    {
        if (is_int($value) || is_string($value) && $value !== '' && preg_match('//u', $value)) {
            return $value;
        }
        $this->deny();
    }

    private function deny(): never
    {
        throw new DapterException(ErrorKind::Access, ErrorCode::AccessDenied, 403);
    }
}
