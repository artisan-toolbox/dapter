<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use ArtisanToolbox\Dapter\Contracts\CollectionSource;
use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\EloquentSource;
use ArtisanToolbox\Dapter\Http\DapterException;
use Illuminate\Container\Container;

final class SourceResolver
{
    /** @internal */
    public static function validName(string $name): bool
    {
        if (! preg_match('/\A[A-Z_a-z][A-Z_a-z0-9]*(?:\.[A-Z_a-z][A-Z_a-z0-9]*)*\z/D', $name)) {
            return false;
        }

        foreach (explode('.', $name) as $segment) {
            $token = token_get_all('<?php '.$segment)[1] ?? null;

            if (! is_array($token) || $token[0] !== T_STRING || in_array(strtolower($segment), ['int', 'float', 'bool', 'string', 'true', 'false', 'null', 'void', 'iterable', 'object', 'resource', 'numeric', 'mixed', 'never', 'self', 'parent', 'static', 'enum', 'readonly'], true)) {
                return false;
            }
        }

        return true;
    }

    public function className(string $name): string
    {
        if (! self::validName($name)) {
            throw DapterException::missing();
        }

        return resolve(SourceRegistry::class)->className($name) ?? app()->getNamespace().'DataSources\\'.str_replace('.', '\\', $name);
    }

    public function resolve(string $name): DataSource
    {
        $class = $this->className($name);

        if (! class_exists($class)) {
            throw DapterException::missing();
        }
        $collection = is_subclass_of($class, CollectionSource::class);
        $eloquent = is_subclass_of($class, EloquentSource::class);

        if ($collection === $eloquent) {
            throw DapterException::configuration();
        }
        $source = Container::getInstance()->build($class);

        if ((! $source instanceof CollectionSource && ! $source instanceof EloquentSource) || ($source instanceof CollectionSource && $source instanceof EloquentSource)) {
            throw DapterException::configuration();
        }

        return $source;
    }
}
