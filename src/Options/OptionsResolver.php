<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Actions\ActionRegistry;
use ArtisanToolbox\Dapter\Attributes;
use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\StoreOptions;
use ReflectionClass;

final class OptionsResolver
{
    public function resolve(DataSource $source): ResolvedStoreOptions
    {
        if (method_exists($source, 'options') && (new ReflectionClass($source))->getMethod('options')->isPublic() && ! $source instanceof WithOptions) {
            throw DapterException::configuration();
        }
        $declaration = $source instanceof WithOptions ? $source->options() : StoreOptions::make();
        $values = $declaration->baselineValues();
        $classes = [];
        $reflection = new ReflectionClass($source);
        do {
            array_unshift($classes, $reflection);
        } while ($reflection = $reflection->getParentClass());
        $advanced = new AdvancedOptionsResolver;
        $configured = $advanced->config($values['declarations'] ?? []);
        $values = array_replace($values, $configured);
        $actionsClass = $values['actions_class'] ?? null;
        foreach ($classes as $class) {
            foreach ($class->getAttributes(Attributes\Actions::class) as $attribute) {
                $actionsClass = $attribute->newInstance()->actionsClass;
            }
        }
        $actionsClass = $declaration->explicitValues()['actions_class'] ?? $actionsClass;
        $values = array_replace($values, $advanced->actionDefaults($actionsClass));
        $fieldLayers = [$configured['fields'] ?? []];
        foreach ($classes as $class) {
            $values = array_replace($values, $advanced->attributes($class, $source));
            $fieldLayer = [];
            foreach ($class->getAttributes() as $attribute) {
                if (is_a($attribute->getName(), FieldAttribute::class, true)) {
                    $setting = $attribute->newInstance();

                    if (! $setting instanceof FieldAttribute) {
                        throw DapterException::configuration();
                    }
                    $fieldLayer[] = $setting->setting();

                    continue;
                }
            }
            $fieldLayers[] = $fieldLayer;
        }
        $fieldLayers[] = $declaration->fieldSettings();
        $fields = (new FieldOptionsResolver)->resolve($fieldLayers);
        $values = array_replace($values, $declaration->explicitValues(), (new ClassOptions)->values($source::class, $declaration));
        [$resource, $broadcasting] = ProjectionOptions::parse($values);
        $mode = $values['paging'] ?? null;
        $size = $values['size'] ?? null;
        $sizes = $values['sizes'] ?? [];
        $guard = $values['guard'] ?? null;
        $access = $values['access'] ?? AccessMode::Required;
        $gate = $values['authorization'] ?? null;

        if (! $mode instanceof PagingMode || ! is_int($size) || $size < 1 || $size > 9007199254740991 || ! is_array($sizes) || ! array_is_list($sizes) || ! is_string($guard) || $guard === '' || ! is_array(config('auth.guards.'.$guard)) || ! $access instanceof AccessMode || ($gate !== null && (! $gate instanceof GateCheck || $gate->ability === ''))) {
            throw DapterException::configuration();
        }
        $normalized = [];
        foreach ($sizes as $option) {
            if (! is_int($option) || $option < 1 || $option > 9007199254740991) {
                throw DapterException::configuration();
            }

            if (! in_array($option, $normalized, true)) {
                $normalized[] = $option;
            }
        }

        if (! in_array($size, $normalized, true)) {
            $normalized[] = $size;
        }

        $driver = $values['driver'] ?? null;

        if ($driver !== null && (! is_string($driver) || ! FieldOptionsResolver::identifier($driver))) {
            throw DapterException::configuration();
        }

        $resolved = $advanced->resolve($values);
        foreach ($resolved[5]->fixedOrder ?? [] as $term) {
            $order = $fields[$term->field]->order ?? null;

            if ($order === null || ! in_array($term->direction, $order->directions, true) || ! in_array($term->sortType, $order->sortTypes, true)) {
                throw DapterException::configuration();
            }
        }
        $result = new ResolvedStoreOptions($mode, $size, $normalized, $guard, $access, $gate, $fields, $driver, ...[...$resolved, $resource, $broadcasting, AdvancedOptionsResolver::strictLiteralSearch($values), BrowserStatePolicy::resolve($values)]);
        (new ActionRegistry)->discover($source, $result);

        return $result;
    }
}
