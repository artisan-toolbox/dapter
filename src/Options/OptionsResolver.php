<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

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
        foreach ($classes as $class) {
            $seen = [];
            foreach ($class->getAttributes() as $attribute) {
                if (! in_array($attribute->getName(), [Attributes\Paging::class, Attributes\PageSize::class, Attributes\PageSizeOptions::class, Attributes\Guard::class, Attributes\PublicAccess::class, Attributes\Authorize::class], true)) {
                    continue;
                }
                $setting = $attribute->newInstance();

                if (! $setting instanceof AttributeSetting) {
                    throw DapterException::configuration();
                }
                $key = $setting->key()->value;

                if (isset($seen[$key])) {
                    throw DapterException::configuration();
                }
                $seen[$key] = true;
                $values[$key] = $setting->value();
            }
        }
        $values = array_replace($values, $declaration->explicitValues());
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

        return new ResolvedStoreOptions($mode, $size, $normalized, $guard, $access, $gate);
    }
}
