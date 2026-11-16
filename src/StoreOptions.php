<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter;

use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\OptionKey;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Options\GateCheck;

final class StoreOptions
{
    /**
     * @param  array<string, mixed>  $baseline
     * @param  array<string, mixed>  $patch
     */
    private function __construct(private readonly array $baseline, private array $patch = []) {}

    public static function make(): self
    {
        return new self([
            'paging' => config('dapter.paging.mode'),
            'size' => config('dapter.paging.per_page'),
            'sizes' => config('dapter.paging.per_page_options'),
            'guard' => config('dapter.access.guard'),
        ]);
    }

    public static function makeWithoutDefaults(): self
    {
        return new self([]);
    }

    public function pagingMode(PagingMode $mode): self
    {
        return $this->with(OptionKey::Paging, $mode);
    }

    public function pageSize(int $size): self
    {
        return $this->with(OptionKey::Size, $size);
    }

    /** @param list<int> $sizes */
    public function pageSizeOptions(array $sizes): self
    {
        return $this->with(OptionKey::Sizes, $sizes);
    }

    public function guard(string $name): self
    {
        return $this->with(OptionKey::Guard, $name);
    }

    public function publicAccess(bool $enabled = true): self
    {
        return $this->with(OptionKey::Access, $enabled ? AccessMode::Public : AccessMode::Required);
    }

    public function authorize(string $ability, mixed $arguments = []): self
    {
        return $this->with(OptionKey::Authorization, new GateCheck($ability, $arguments));
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function explicitValues(): array
    {
        return $this->patch;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function baselineValues(): array
    {
        return $this->baseline;
    }

    private function with(OptionKey $key, mixed $value): self
    {
        $copy = clone $this;
        $copy->patch[$key->value] = $value;

        return $copy;
    }
}
