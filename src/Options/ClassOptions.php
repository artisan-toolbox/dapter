<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\StoreOptions;
use ReflectionClass;

/** @internal */
final class ClassOptions
{
    /** @param class-string<DataSource> $source
     * @return array<string,mixed> */
    public function values(string $source, StoreOptions $declaration): array
    {
        $values = $declaration->baselineValues();
        $classes = [];
        $class = new ReflectionClass($source);
        do {
            array_unshift($classes, $class);
        } while ($class = $class->getParentClass());
        foreach ($classes as $class) {
            $seen = [];
            foreach ($class->getAttributes() as $attribute) {
                if (! is_a($attribute->getName(), AttributeSetting::class, true)) {
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

        return array_replace($values, $declaration->explicitValues());
    }
}
