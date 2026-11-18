<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use ArtisanToolbox\Dapter\Broadcasting\LiveDefinition;
use ArtisanToolbox\Dapter\Broadcasting\LiveSourceRegistration;
use ArtisanToolbox\Dapter\Broadcasting\PrivateChannel;
use ArtisanToolbox\Dapter\Broadcasting\RecipientContexts;
use ArtisanToolbox\Dapter\Contracts\CollectionSource;
use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\EloquentSource;
use ArtisanToolbox\Dapter\Http\DapterException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Broadcasting\Factory;

final class SourceRegistry
{
    /** @var array<string, class-string<DataSource>> */
    private array $sources = [];

    /** @param class-string<DataSource> $class */
    public function register(string $alias, string $class): void
    {
        if (! SourceResolver::validName($alias) || in_array(strtolower($alias), array_map('strtolower', array_keys($this->sources)), true) || is_subclass_of($class, CollectionSource::class) === is_subclass_of($class, EloquentSource::class)) {
            throw DapterException::configuration();
        }
        $this->sources[$alias] = $class;
    }

    /** @return class-string<DataSource>|null */
    public function className(string $alias): ?string
    {
        return $this->sources[$alias] ?? null;
    }

    /** @var array<string,LiveDefinition> */
    private array $live = [];

    private bool $sealed = false;

    public function registerLive(string $alias, LiveSourceRegistration $registration): void
    {
        if ($this->sealed) {
            throw DapterException::configuration();
        }
        $definition = new LiveDefinition($alias, $registration);
        foreach ($this->live as $existing) {
            if (LiveDefinition::overlaps($existing->pattern, $definition->pattern)) {
                throw DapterException::configuration();
            }
        }
        $this->register($alias, $registration->source);
        $this->live[$alias] = $definition;
    }

    /** @internal */
    public function live(string $alias): ?LiveDefinition
    {
        if (! $this->sealed) {
            throw DapterException::configuration();
        }

        return $this->live[$alias] ?? null;
    }

    /** @internal */
    public function seal(): void
    {
        if ($this->sealed) {
            return;
        }

        if ($this->live !== []) {
            $broadcaster = resolve(Factory::class)->connection();
            $existingPatterns = $broadcaster->getChannels()->keys()->all();
            foreach ($existingPatterns as $existing) {
                foreach (explode('.', $existing) as $segment) {
                    if (! preg_match('/\A(?:[A-Za-z0-9_=-]+|\{[A-Za-z_][A-Za-z0-9_]*\})\z/D', $segment)) {
                        throw DapterException::configuration();
                    }
                }
            }
            foreach ($this->live as $definition) {
                foreach ($existingPatterns as $existing) {
                    if (LiveDefinition::overlaps($definition->pattern, $existing)) {
                        throw DapterException::configuration();
                    }
                }
            }
            foreach ($this->live as $definition) {
                if ($definition->broadcasting->channel instanceof PrivateChannel) {
                    $broadcaster->channel($definition->pattern, static fn (?Authenticatable $user, string $context): bool => resolve(RecipientContexts::class)->admit($definition->alias, $user, $context), ['guards' => [$definition->guard]]);
                }
            }
        }
        $this->sealed = true;
    }
}
