<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers\Comparisons;

use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use Collator;

/** @internal */
final readonly class ResolvedComparisons
{
    /**
     * @param  array<string,array{collator:Collator,metadata:array<string,mixed>}>  $locales
     * @param  array<string,string>  $fullText
     * @param  array<string,string>  $globalFullText
     * @param  array<string,string>  $vectors
     */
    public function __construct(public ResolvedStoreOptions $options, private array $locales, private array $fullText, private array $globalFullText, public ?ResolvedLiteralSearch $literal = null, private array $vectors = []) {}

    public function compare(string $field, string $left, string $right): int
    {
        if (preg_match('//u', $left) !== 1 || preg_match('//u', $right) !== 1) {
            throw DapterException::configuration();
        }
        $collator = $this->locales[$field]['collator'] ?? throw DapterException::configuration();
        $result = $collator->compare($left, $right);

        if ($result === false) {
            throw DapterException::configuration();
        }

        return $result;
    }

    /** @return array<string,mixed> */
    public function orderMetadata(string $field): array
    {
        return isset($this->locales[$field]) ? ['locale' => $this->locales[$field]['metadata']] : [];
    }

    /** @return array<string,mixed> */
    public function searchMetadata(string $field, bool $global = false): array
    {
        if (! $global && isset($this->vectors[$field])) {
            $vector = $this->options->fields[$field]->search->options->vector;

            return ['valueType' => 'vector', 'vector' => ['engine' => 'pgsql', 'metric' => 'l2', 'dimensions' => $vector->dimensions, 'maxDistance' => $vector->maxDistance, 'input' => 'prepared_vector', 'precision' => 'float32', 'maxCoordinateMagnitude' => 1000000, 'threshold' => 'inclusive', 'ranking' => 'filter_only']];
        }
        $configuration = ($global ? $this->globalFullText : $this->fullText)[$field] ?? null;

        return $configuration === null ? [] : ['fullText' => ['engine' => 'pgsql', 'configuration' => $configuration, 'input' => 'plain_text', 'maxBytes' => 512, 'ranking' => 'filter_only']];
    }

    public function configuration(string $field, bool $global): string
    {
        return ($global ? $this->globalFullText : $this->fullText)[$field] ?? throw DapterException::configuration();
    }

    public function vectorSchema(string $field): string
    {
        return $this->vectors[$field] ?? throw DapterException::configuration();
    }
}
