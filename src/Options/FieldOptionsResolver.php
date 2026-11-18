<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Support\ScalarValue;
use ArtisanToolbox\Dapter\Support\TrustedHandlerBinder;
use BackedEnum;

/** @internal */
final class FieldOptionsResolver
{
    /**
     * @param  list<list<FieldSetting>>  $layers
     * @return array<string, FieldDefinition>
     */
    public function resolve(array $layers): array
    {
        $entries = [];
        foreach ($layers as $layer) {
            $seen = [];
            foreach ($layer as $entry) {
                if (($entry->kind === FieldOperation::Filter) !== ($entry->id !== null) || ! self::identifier($entry->field) || ($entry->id !== null && ! self::identifier($entry->id)) || isset($seen[$entry->key()])) {
                    throw DapterException::configuration();
                }
                $seen[$entry->key()] = true;
                $entries[$entry->key()] = $entry;
            }
        }
        $fields = [];
        foreach ($entries as $entry) {
            $definition = $entry->definition;

            if ($definition === null) {
                continue;
            }

            if (! match ($entry->kind) {
                FieldOperation::Search => $definition instanceof SearchDefinition,
                FieldOperation::Order => $definition instanceof OrderDefinition,
                FieldOperation::Filter => $definition instanceof FilterDefinition,
            }) {
                throw DapterException::configuration();
            }
            $target = $definition->target ?? $entry->field;

            if (! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*(?:\.[A-Za-z_][A-Za-z_0-9]*)*\z/D', $target)) {
                throw DapterException::configuration();
            }
            $settings = $definition->options;

            if ($definition instanceof SearchDefinition) {
                self::fullText(in_array(MatchType::FullText, $definition->matchTypes, true), $settings?->fullText);

                if ($settings?->locale !== null) {
                    throw DapterException::configuration();
                }
            } elseif ($definition instanceof OrderDefinition) {
                $locale = in_array(SortType::Locale, $definition->sortTypes, true);

                if ($locale !== ($settings?->locale !== null) || $settings?->fullText !== null || $locale && $definition->valueType !== null && $definition->valueType !== ValueType::String) {
                    throw DapterException::configuration();
                }

                if ($settings?->locale !== null && ! preg_match('/\A[a-zA-Z]{2,3}(?:[-_][a-zA-Z]{4})?(?:[-_](?:[a-zA-Z]{2}|[0-9]{3}))?\z/D', $settings->locale->locale)) {
                    throw DapterException::configuration();
                }
            } elseif ($settings?->locale !== null || $settings?->fullText !== null) {
                throw DapterException::configuration();
            }
            $output = $settings?->output;

            if ($output === '') {
                throw DapterException::configuration();
            }
            $field = $fields[$entry->field] ?? new FieldDefinition($target, output: $output);

            if ($field->output !== $output) {
                throw DapterException::configuration();
            }

            if ($field->target !== $target) {
                throw DapterException::configuration();
            }

            if ($definition instanceof SearchDefinition) {
                self::enumList($definition->matchTypes, MatchType::class);

                if ($definition->valueType === ValueType::Boolean || $definition->valueType !== ValueType::String && $definition->matchTypes !== [MatchType::Equals]) {
                    throw DapterException::configuration();
                }
                $field = new FieldDefinition($target, $definition, $field->order, $field->filters, $output);
            } elseif ($definition instanceof OrderDefinition) {
                self::enumList($definition->sortTypes, SortType::class);
                self::enumList($definition->directions, SortDirection::class);
                $field = new FieldDefinition($target, $field->search, $definition, $field->filters, $output);
            } else {
                $domain = TrustedHandlerBinder::domain($definition->values);

                if ($domain === []) {
                    throw DapterException::configuration();
                }
                $type = $definition->valueType ?? (array_filter($domain, static fn (mixed $value): bool => $value !== null) === [] ? null : ScalarValue::infer($domain));
                $values = [];
                foreach ($domain as $value) {
                    $value = $value === null ? null : ScalarValue::normalize($value, $type, true);

                    if (in_array($value, $values, true)) {
                        throw DapterException::configuration();
                    }
                    $values[] = $value;
                }
                $filters = $field->filters;
                $filters[$entry->id] = new FilterDefinition($values, $definition->multiple, $definition->empty, $target, $type, $definition->options);
                $field = new FieldDefinition($target, $field->search, $field->order, $filters, $output);
            }
            $fields[$entry->field] = $field;
        }

        return $fields;
    }

    public static function fullText(bool $enabled, ?FullTextOptions $options): void
    {
        if ($enabled !== ($options !== null) || $options !== null && ! preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\.[A-Za-z_][A-Za-z_0-9]*\z/D', $options->configuration)) {
            throw DapterException::configuration();
        }
    }

    public static function identifier(string $value): bool
    {
        return (bool) preg_match('/\A[A-Za-z_][A-Za-z_0-9]*\z/D', $value);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @param  class-string  $type
     */
    private static function enumList(array $values, string $type): void
    {
        if (! array_is_list($values) || $values === []) {
            throw DapterException::configuration();
        }
        $seen = [];
        foreach ($values as $value) {
            if (! $value instanceof $type || ! $value instanceof BackedEnum || in_array($value->value, $seen, true)) {
                throw DapterException::configuration();
            }
            $seen[] = $value->value;
        }
    }
}
