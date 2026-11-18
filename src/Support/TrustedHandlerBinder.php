<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\HandlerOptions;
use BackedEnum;
use Closure;
use Illuminate\Container\Container;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use UnitEnum;

/** @internal */
final class TrustedHandlerBinder
{
    /** @param array<array-key,mixed>|string|UnitEnum $values
     * @return list<string|int|float|bool|null>
     */
    public static function domain(array|string|UnitEnum $values): array
    {
        if (is_string($values)) {
            if (! enum_exists($values)) {
                throw DapterException::configuration();
            }
            $values = $values::cases();
        } elseif ($values instanceof UnitEnum) {
            $values = [$values];
        }

        if (! array_is_list($values) || $values === []) {
            throw DapterException::configuration();
        }
        $result = [];
        foreach ($values as $value) {
            $value = $value instanceof BackedEnum ? $value->value : ($value instanceof UnitEnum ? $value->name : $value);

            if (! is_scalar($value) && $value !== null || is_float($value) && (! is_finite($value) || abs($value) > 9007199254740991) || is_int($value) && abs($value) > 9007199254740991 || in_array($value, $result, true)) {
                throw DapterException::configuration();
            }
            $value = is_float($value) && floor($value) === $value ? (int) $value : $value;

            if (in_array($value, $result, true)) {
                throw DapterException::configuration();
            }
            $result[] = $value;
        }

        return $result;
    }

    /** @param array<array-key,mixed>|string|UnitEnum $values
     * @return list<string|int|float|bool|null>
     */
    public function filterDomain(array|string|UnitEnum $values, Closure|string $apply, ?HandlerOptions $options = null): array
    {
        $handler = $this->describe($apply);
        $type = ($handler->reflection->getParameters()[1] ?? null)?->getType();
        $enum = $options?->binding?->enum;

        if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && enum_exists($type->getName())) {
            $enum = $type->getName();
        }
        $members = is_string($values) && enum_exists($values) ? $values::cases() : ($values instanceof UnitEnum ? [$values] : $values);

        if (is_array($members) && $enum !== null) {
            foreach ($members as $member) {
                if ($member instanceof UnitEnum && ! $member instanceof $enum) {
                    throw DapterException::configuration();
                }
            }
        }

        return self::domain($values);
    }

    public function signature(TrustedHandler $handler, string $family, int $operands, ?string $returnFamily = null): void
    {
        $parameters = $handler->reflection->getParameters();

        if (count($parameters) !== $operands) {
            throw DapterException::configuration();
        }

        if (! $this->accepts($parameters[0]->getType(), $family) || ! $this->accepts($handler->reflection->getReturnType(), $returnFamily ?? $family)) {
            throw DapterException::configuration();
        }
        foreach ($parameters as $parameter) {
            if ($parameter->isPassedByReference() || $parameter->isVariadic() || $parameter->isOptional()) {
                throw DapterException::configuration();
            }
        }

        if ($returnFamily !== null && $operands === 2 && ! $this->accepts($parameters[1]->getType(), $family)) {
            throw DapterException::configuration();
        }
    }

    private function accepts(?ReflectionType $type, string $family): bool
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if ($this->accepts($member, $family)) {
                    return true;
                }
            }

            return false;
        }

        return $type instanceof ReflectionNamedType && ! $type->isBuiltin() && ! $type->allowsNull() && is_a($family, $type->getName(), true);
    }

    public function describe(Closure|string $handler): TrustedHandler
    {
        if ($handler instanceof Closure) {
            return new TrustedHandler($handler, new ReflectionFunction($handler));
        }

        if (! class_exists($handler)) {
            throw DapterException::configuration();
        }
        $class = new ReflectionClass($handler);

        if (! $class->isInstantiable() || ! $class->hasMethod('__invoke')) {
            throw DapterException::configuration();
        }
        $method = $class->getMethod('__invoke');

        if (! $method->isPublic() || $method->isStatic()) {
            throw DapterException::configuration();
        }

        return new TrustedHandler($handler, $method, $handler);
    }

    public function method(DataSource $source, ReflectionMethod $method): Closure
    {
        if (! $method->isPrivate() || $method->isStatic()) {
            throw DapterException::configuration();
        }

        return $method->getClosure($source);
    }

    public function bindValue(TrustedHandler $handler, mixed $value, ?HandlerOptions $options = null): mixed
    {
        $parameter = $handler->reflection->getParameters()[1] ?? null;

        if ($parameter === null) {
            throw DapterException::configuration();
        }
        $type = $parameter->getType();

        if ($type instanceof ReflectionUnionType) {
            $binding = $options?->binding;

            if ($binding !== null) {
                if (! enum_exists($binding->enum)) {
                    throw DapterException::configuration();
                }
                $compatible = array_filter($type->getTypes(), static fn (ReflectionType $member): bool => $member instanceof ReflectionNamedType && ($binding->elements
                    ? $member->isBuiltin() && in_array($member->getName(), ['array', 'iterable'], true)
                    : ! $member->isBuiltin() && is_a($binding->enum, $member->getName(), true)));

                if ($compatible === []) {
                    throw DapterException::configuration();
                }
                $type = reset($compatible);
            } else {
                foreach ($type->getTypes() as $member) {
                    if (! $member instanceof ReflectionNamedType || ! $member->isBuiltin()) {
                        throw DapterException::configuration();
                    }
                }
                foreach ($type->getTypes() as $member) {
                    if ($this->builtin($member->getName(), $value)) {
                        return $value;
                    }
                }

                throw DapterException::validation();
            }
        }

        if (! $type instanceof ReflectionNamedType) {
            throw DapterException::configuration();
        }

        if ($value === null) {
            if (! $parameter->allowsNull()) {
                throw DapterException::validation();
            }

            return null;
        }
        $binding = $options?->binding;
        $enum = $binding?->enum;

        if (! $type->isBuiltin() && enum_exists($type->getName())) {
            if ($enum !== null && $enum !== $type->getName()) {
                throw DapterException::configuration();
            }
            $enum = $type->getName();
        }

        if ($enum !== null) {
            if (! enum_exists($enum)) {
                throw DapterException::configuration();
            }
            $nullable = $parameter->allowsNull();
            $convert = static function (mixed $wire) use ($enum, $nullable): ?UnitEnum {
                if ($wire === null && $nullable) {
                    return null;
                }
                foreach ($enum::cases() as $case) {
                    if (($case instanceof BackedEnum ? $case->value : $case->name) === $wire) {
                        return $case;
                    }
                }

                throw DapterException::validation();
            };
            $value = $binding?->elements ? (is_array($value) ? array_map($convert, $value) : throw DapterException::validation()) : $convert($value);
        }

        if ($type->isBuiltin()) {
            $valid = $this->builtin($type->getName(), $value);

            if (! $valid) {
                throw DapterException::validation();
            }
        } elseif (! $value instanceof ($type->getName())) {
            throw DapterException::validation();
        }

        return $value;
    }

    private function builtin(string $type, mixed $value): bool
    {
        return match ($type) {
            'mixed' => true,'null' => $value === null,'array' => is_array($value),'string' => is_string($value),'int' => is_int($value),'float' => is_int($value) || is_float($value),'bool' => is_bool($value),'iterable' => is_iterable($value),default => false,
        };
    }

    /** @param list<mixed> $arguments */
    public function invoke(TrustedHandler $handler, array $arguments): mixed
    {
        $callback = $handler->handler;

        if ($callback instanceof Closure) {
            return $callback(...$arguments);
        }

        if (! $handler->reflection instanceof ReflectionMethod) {
            throw DapterException::configuration();
        }

        return $handler->reflection->invokeArgs(Container::getInstance()->build($callback), $arguments);
    }
}
