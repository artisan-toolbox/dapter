<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Actions;

use ArtisanToolbox\Dapter\Attributes as A;
use ArtisanToolbox\Dapter\Contracts\CollectionSource;
use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\EloquentSource;
use ArtisanToolbox\Dapter\Drivers\WherePredicates;
use ArtisanToolbox\Dapter\Enums\ActionTransport;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\ActionPolicy;
use ArtisanToolbox\Dapter\Options\AdvancedOptionsResolver;
use ArtisanToolbox\Dapter\Options\FieldOptionsResolver;
use ArtisanToolbox\Dapter\Options\RecordTarget;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use Symfony\Component\HttpFoundation\RedirectResponse;

/** @internal */
final class ActionRegistry
{
    /** @return array<string,ActionDefinition> */
    public function discover(DataSource $source, ResolvedStoreOptions $options): array
    {
        $definitions = [];
        $seen = [];
        $reflection = new ReflectionClass($source);
        do {
            foreach ($reflection->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $reflection->getName() || $method->getAttributes(A\Action::class) === []) {
                    continue;
                }

                if (! $method->isPrivate() || $method->isStatic()) {
                    throw DapterException::configuration();
                }
                $this->add($definitions, $seen, $method, null, $options);
            }
        } while ($reflection = $reflection->getParentClass());

        if ($options->actionsClass !== null) {
            $class = new ReflectionClass($options->actionsClass);

            if (! $class->isInstantiable()) {
                throw DapterException::configuration();
            }
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $class->getName() || ! $method->isPublic() || $method->isStatic() || str_starts_with($method->getName(), '__')) {
                    continue;
                }
                $this->add($definitions, $seen, $method, $options->actionsClass, $options);
            }
        }
        foreach ($options->actionPolicies as $id => $policy) {
            if (! isset($definitions[$id])) {
                throw DapterException::configuration();
            }
        }

        return $definitions;
    }

    /** @param array<string,ActionDefinition> $definitions
     * @param  array<string,true>  $seen
     * @param  class-string|null  $owner
     */
    private function add(array &$definitions, array &$seen, ReflectionMethod $method, ?string $owner, ResolvedStoreOptions $options): void
    {
        $attributes = $method->getAttributes(A\Action::class);

        if (count($attributes) > 1 || $attributes === [] && $owner === null || $method->getAttributes(A\GlobalFilter::class) !== [] || $method->getAttributes(A\GlobalOrder::class) !== []) {
            throw DapterException::configuration();
        }
        $a = $attributes === [] ? new A\Action : $attributes[0]->newInstance();
        $id = $a->id ?? $method->getName();

        if (! FieldOptionsResolver::identifier($id) || isset($seen[strtolower($id)]) || ! array_is_list($a->transports) || $a->transports === []) {
            throw DapterException::configuration();
        }
        $modes = [];
        foreach ($a->transports as $mode) {
            if (! $mode instanceof ActionTransport || in_array($mode, $modes, true)) {
                throw DapterException::configuration();
            }$modes[] = $mode;
        }
        $return = $method->getReturnType();
        $returns = $return instanceof ReflectionUnionType ? $return->getTypes() : [$return];

        if (in_array(ActionTransport::Http, $modes, true) && count($returns) === 1 && $return instanceof ReflectionNamedType && is_a($return->getName(), RedirectResponse::class, true)) {
            throw DapterException::configuration();
        }
        $policy = null;
        $auth = $method->getAttributes(A\ActionAuthorize::class);

        if (count($auth) > 1) {
            throw DapterException::configuration();
        }

        if ($auth !== []) {
            $p = $auth[0]->newInstance();

            if ($p->id !== null && $p->id !== $id || $p->ability === '') {
                throw DapterException::configuration();
            }$policy = new ActionPolicy($p->ability, $p->arguments, $p->target);
        }
        $policy = array_key_exists($id, $options->actionPolicies) ? $options->actionPolicies[$id] : $policy;
        $form = null;
        foreach ($method->getParameters() as $parameter) {
            $t = $parameter->getType();

            if (! $t instanceof ReflectionNamedType || $t->isBuiltin() || $parameter->isVariadic() || $parameter->isPassedByReference() || enum_exists($t->getName()) || is_a($t->getName(), Model::class, true)) {
                throw DapterException::configuration();
            }
            $type = $t->getName();

            if (is_a($type, FormRequest::class, true)) {
                if ($form !== null) {
                    throw DapterException::configuration();
                }$form = $type;
            }
        }
        $explicit = $form !== null && (new ReflectionClass($form))->hasMethod('authorize') && (new ReflectionClass($form))->getMethod('authorize')->getDeclaringClass()->getName() !== FormRequest::class;

        if ($policy === null && ! $explicit) {
            throw DapterException::configuration();
        }

        if ($policy?->target !== null) {
            $target = $policy->target;

            if (! FieldOptionsResolver::identifier($target->input) || ! in_array($target->valueType, [ValueType::String, ValueType::Integer], true) || $target->target !== null && ! AdvancedOptionsResolver::target($target->target) || $target->output === '') {
                throw DapterException::configuration();
            }
        }
        $seen[strtolower($id)] = true;
        $definitions[$id] = new ActionDefinition($id, $a->method, $modes, $method, $owner, $policy, $form);
    }

    /** @param iterable<array-key,mixed> $rows
     * @return array<string,mixed>
     */
    public function metadata(DataSource $source, ResolvedStoreOptions $options, iterable $rows, mixed $user): array
    {
        $metadata = [];
        $name = request()->route('source');

        if (! is_string($name)) {
            return [];
        }
        foreach ($this->discover($source, $options) as $id => $action) {
            if ($action->policy === null) {
                continue;
            }$p = $action->policy;
            $base = ['method' => $action->method->value, 'transports' => array_map(fn (ActionTransport $t): string => $t->value, $action->transports)];

            if ($p->target === null) {
                if (! resolve(Gate::class)->forUser($user)->allows($p->ability, $p->arguments)) {
                    continue;
                }$metadata[$id] = [...$base, 'url' => route('dapter.action', ['source' => $name, 'action' => $id]), 'target' => null];

                continue;
            }
            $target = $p->target;
            $values = [];
            foreach ($rows as $row) {
                $output = $target->output ?? ($target->target !== null ? last(explode('.', $target->target)) : ($row instanceof Model ? $row->getKeyName() : 'id'));
                $wire = $this->rowValue($row, $output);

                try {
                    $wire = $this->selector($wire, $target);
                    $record = $row;
                } catch (DapterException) {
                    continue;
                }

                if ($record === null || ! resolve(Gate::class)->forUser($user)->allows($p->ability, $this->recordArguments($record, $p->arguments))) {
                    continue;
                }

                if (array_filter($values, fn (array $v): bool => $v['value'] === $wire) !== []) {
                    continue;
                }
                $values[] = ['value' => $wire, 'url' => route('dapter.action', ['source' => $name, 'action' => $id, ...ActionTargetUrl::parameters($wire)])];
            }

            if ($values !== []) {
                $metadata[$id] = [...$base, 'target' => ['input' => $target->input, 'valueType' => $target->valueType->value, 'values' => $values]];
            }
        }

        return $metadata;
    }

    /** @return Builder<Model>|Collection<array-key,mixed> */
    public function mandatory(DataSource $source, bool $forRecord = false): Builder|Collection
    {
        if ($source instanceof EloquentSource) {
            $query = $source->source();

            if ($forRecord) {
                $this->rejectRecordCloneCallbacks($query);
            }

            return clone $query;
        }

        if ($source instanceof CollectionSource) {
            return collect($source->source());
        }

        throw DapterException::configuration();
    }

    /** @param Builder<Model>|Collection<array-key,mixed> $mandatory */
    public function record(Builder|Collection $mandatory, RecordTarget $target, string|int $wire): mixed
    {
        $column = $target->target ?? ($mandatory instanceof Builder ? $mandatory->getModel()->getKeyName() : 'id');

        if ($mandatory instanceof Builder) {
            $this->rejectRecordCloneCallbacks($mandatory);
            $query = (clone $mandatory)->applyScopes()->withoutGlobalScopes();
            $this->rejectRecordCloneCallbacks($query);
            $base = $query->getQuery();

            if ($base->limit !== null || $base->offset !== null || $base->groupLimit !== null || $base->unionLimit !== null || $base->unionOffset !== null) {
                throw DapterException::configuration();
            }

            if ($base->groups || $base->havings || $base->aggregate !== null
                || $base->beforeQueryCallbacks !== []
                || (is_array($base->distinct) && $base->getGrammar() instanceof PostgresGrammar)
                || array_any($base->columns ?? [], static fn (mixed $column): bool => ! is_string($column))) {
                throw DapterException::configuration();
            }

            // Both Laravel builders can replace their result collection and expose no callback getter.
            foreach ([$query, $base] as $builder) {
                if ((new ReflectionProperty($builder, 'afterQueryCallbacks'))->getValue($builder) !== []) {
                    throw DapterException::configuration();
                }
            }
            WherePredicates::group($query);
            $matches = $query->where($column, $wire)->limit(2)->get();

            if ($matches->count() > 1) {
                throw DapterException::configuration();
            }
            $record = $matches->first();
            $output = $target->output ?? last(explode('.', $column));

            return $record !== null && $this->rowValue($record, $output) === $wire ? $record : null;
        }
        $matches = $mandatory->filter(fn (mixed $row): bool => $this->rowValue($row, $column) === $wire);

        if ($matches->count() > 1) {
            throw DapterException::configuration();
        }

        return $matches->first();
    }

    /** @param Builder<Model> $query */
    private function rejectRecordCloneCallbacks(Builder $query): void
    {
        if ((new ReflectionProperty(Builder::class, 'onCloneCallbacks'))->getValue($query) !== []) {
            throw DapterException::configuration();
        }
    }

    /** @return list<mixed> */
    public function recordArguments(mixed $record, mixed $arguments): array
    {
        return [$record, ...(is_array($arguments) ? array_values($arguments) : [$arguments])];
    }

    public function selector(mixed $value, RecordTarget $target): string|int
    {
        if ($target->valueType === ValueType::String && is_string($value) && $value !== '' && strlen($value) <= 2048) {
            return $value;
        }

        if ($target->valueType === ValueType::Integer && is_int($value) && abs($value) <= 9007199254740991) {
            return $value;
        }

        throw DapterException::validation();
    }

    private function rowValue(mixed $row, string $key): mixed
    {
        return is_array($row) ? ($row[$key] ?? null) : ($row instanceof Model ? $row->getAttribute($key) : (is_object($row) ? ($row->{$key} ?? null) : null));
    }
}
