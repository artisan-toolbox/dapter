<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

/** @internal */
final readonly class FieldSetting
{
    public function __construct(public string $field, public FieldOperation $kind, public ?string $id, public SearchDefinition|OrderDefinition|FilterDefinition|null $definition) {}

    public function key(): string
    {
        return $this->field.'|'.$this->kind->value.'|'.($this->id ?? '');
    }
}
