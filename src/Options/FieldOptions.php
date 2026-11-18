<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

final readonly class FieldOptions
{
    public function __construct(public ?string $output = null, public ?LocaleOptions $locale = null, public ?FullTextOptions $fullText = null, public ?VectorOptions $vector = null) {}
}
