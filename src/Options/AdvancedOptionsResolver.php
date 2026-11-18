<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Options;

use ArtisanToolbox\Dapter\Attributes as A;
use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Enums\EmptySelectionPolicy;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Support\ScalarValue;
use ArtisanToolbox\Dapter\Support\TrustedHandlerBinder;
use ReflectionClass;

/** @internal */
final class AdvancedOptionsResolver
{
    /** @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public function config(array $config): array
    {
        $this->primitives($config);
        $this->keys($config, ['fields', 'global', 'multi_order', 'search_match', 'query', 'actions_class', 'action_policies', 'strict_literal_search']);
        $values = [];

        if (array_key_exists('strict_literal_search', $config)) {
            $values['strict_literal_search'] = self::strictLiteralSearch($config);
        }
        foreach ($config['fields'] ?? [] as $field => $definitions) {
            $this->keys($definitions, ['search', 'order', 'filters']);

            if (array_key_exists('search', $definitions) && $definitions['search'] === null) {
                $values['fields'][] = new FieldSetting($field, FieldOperation::Search, null, null);
            }

            if (isset($definitions['search'])) {
                $d = $definitions['search'];
                $this->keys($d, ['matchTypes', 'target', 'valueType', 'options']);
                $values['fields'][] = new FieldSetting($field, FieldOperation::Search, null, new SearchDefinition(array_map(fn (string $v): MatchType => MatchType::from($v), array_values($d['matchTypes'] ?? ['contains'])), $d['target'] ?? null, ValueType::from($d['valueType'] ?? 'string'), $this->fieldOptions($d['options'] ?? null)));
            }

            if (array_key_exists('order', $definitions) && $definitions['order'] === null) {
                $values['fields'][] = new FieldSetting($field, FieldOperation::Order, null, null);
            }

            if (isset($definitions['order'])) {
                $d = $definitions['order'];
                $this->keys($d, ['sortTypes', 'directions', 'target', 'valueType', 'options']);
                $values['fields'][] = new FieldSetting($field, FieldOperation::Order, null, new OrderDefinition(array_map(fn (string $v): SortType => SortType::from($v), array_values($d['sortTypes'] ?? ['native'])), array_map(fn (string $v): SortDirection => SortDirection::from($v), array_values($d['directions'] ?? ['asc', 'desc'])), $d['target'] ?? null, isset($d['valueType']) ? ValueType::from($d['valueType']) : null, $this->fieldOptions($d['options'] ?? null)));
            }
            foreach ($definitions['filters'] ?? [] as $id => $d) {
                if ($d !== null) {
                    $this->keys($d, ['values', 'multiple', 'empty', 'target', 'valueType', 'options']);
                }
                $values['fields'][] = new FieldSetting($field, FieldOperation::Filter, $id, $d === null ? null : new FilterDefinition($d['values'], $d['multiple'] ?? false, EmptySelectionPolicy::from($d['empty'] ?? 'clear'), $d['target'] ?? null, isset($d['valueType']) ? ValueType::from($d['valueType']) : null, $this->fieldOptions($d['options'] ?? null)));
            }
        }
        $global = $config['global'] ?? [];
        $this->keys($global, ['search', 'filters', 'orders']);

        if (array_key_exists('search', $global)) {
            $values['global.search'] = $global['search'] === null ? null : array_map(function (array $d): SearchTarget {
                $this->keys($d, ['target', 'valueType', 'matchType', 'fullText']);

                return new SearchTarget($d['target'], ValueType::from($d['valueType'] ?? 'string'), MatchType::from($d['matchType'] ?? 'contains'), $this->fullText($d['fullText'] ?? null));
            }, $global['search']);
        }
        foreach ($global['filters'] ?? [] as $id => $d) {
            if ($d !== null) {
                $this->keys($d, ['values', 'apply', 'multiple', 'empty', 'valueType', 'options']);
            }
            $values['global.filter.'.$id] = $d === null ? null : new GlobalFilterDefinition((new TrustedHandlerBinder)->filterDomain($d['values'], $d['apply'], $this->handlerOptions($d['options'] ?? null)), $d['apply'], $d['multiple'] ?? false, EmptySelectionPolicy::from($d['empty'] ?? 'clear'), isset($d['valueType']) ? ValueType::from($d['valueType']) : null, $this->handlerOptions($d['options'] ?? null));
        }
        foreach ($global['orders'] ?? [] as $id => $d) {
            if ($d !== null) {
                $this->keys($d, ['label', 'apply', 'options']);
            }
            $values['global.order.'.$id] = $d === null ? null : new GlobalOrderDefinition($d['label'], $d['apply'], $this->handlerOptions($d['options'] ?? null));
        }

        if (isset($config['multi_order'])) {
            $d = $config['multi_order'];
            $this->keys($d, ['enabled', 'max']);
            $values['multi_order'] = [$d['enabled'] ?? true, $d['max'] ?? 1];
        }

        if (isset($config['search_match'])) {
            $d = $config['search_match'];
            $this->keys($d, ['default', 'allowed']);
            $values['search_match'] = [SearchMatch::from($d['default'] ?? 'all'), array_map(fn (string $v): SearchMatch => SearchMatch::from($v), $d['allowed'] ?? ['all'])];
        }

        if (isset($config['query'])) {
            $d = $config['query'];
            $this->keys($d, ['identity', 'fixedOrder', 'counts']);
            $identityConfig = $d['identity'] ?? null;

            if ($identityConfig !== null) {
                $this->keys($identityConfig, ['columns']);
                foreach ($identityConfig['columns'] as $column) {
                    $this->keys($column, ['target', 'output', 'valueType']);
                }
            }
            foreach ($d['fixedOrder'] ?? [] as $term) {
                $this->keys($term, ['field', 'direction', 'sortType']);
            }
            $identity = isset($d['identity']) ? new ResultIdentity(array_map(fn (array $c): IdentityColumn => new IdentityColumn($c['target'], $c['output'], ValueType::from($c['valueType'])), array_values($d['identity']['columns']))) : null;
            $values['query'] = new QueryOptions($identity, array_map(fn (array $t): FixedOrderTerm => new FixedOrderTerm($t['field'], SortDirection::from($t['direction'] ?? 'asc'), SortType::from($t['sortType'] ?? 'native')), array_values($d['fixedOrder'] ?? [])), $d['counts'] ?? null);
        }

        if (isset($config['actions_class'])) {
            $values['actions_class'] = $config['actions_class'];
        }
        foreach ($config['action_policies'] ?? [] as $id => $d) {
            $values['action_policy.'.$id] = $d === null ? null : $this->policy($d);
        }

        return $values;
    }

    /** @return array<string,mixed> */
    public function actionDefaults(?string $owner): array
    {
        if ($owner === null) {
            return [];
        }

        if (! class_exists($owner)) {
            throw DapterException::configuration();
        }
        $class = new ReflectionClass($owner);
        $policies = [];
        $add = static function (string $id, A\ActionAuthorize $policy) use (&$policies): void {
            $key = 'action_policy.'.$id;

            if (array_key_exists($key, $policies) || $policy->id !== null && $policy->id !== $id) {
                throw DapterException::configuration();
            }
            $policies[$key] = new ActionPolicy($policy->ability, $policy->arguments, $policy->target);
        };
        foreach ($class->getAttributes(A\ActionAuthorize::class) as $attribute) {
            $policy = $attribute->newInstance();

            if ($policy->id === null) {
                throw DapterException::configuration();
            }$add($policy->id, $policy);
        }
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName() || ! $method->isPublic() || $method->isStatic() || str_starts_with($method->getName(), '__')) {
                continue;
            }
            $action = $method->getAttributes(A\Action::class);
            $id = $action === [] ? $method->getName() : ($action[0]->newInstance()->id ?? $method->getName());
            foreach ($method->getAttributes(A\ActionAuthorize::class) as $attribute) {
                $add($id, $attribute->newInstance());
            }
        }

        return $policies;
    }

    /** @param ReflectionClass<object> $class
     * @return array<string,mixed>
     */
    public function attributes(ReflectionClass $class, DataSource $source): array
    {
        $values = [];
        $add = static function (string $key, mixed $value) use (&$values): void {
            if (array_key_exists($key, $values)) {
                throw DapterException::configuration();
            } $values[$key] = $value;
        };
        foreach ($class->getAttributes() as $attribute) {
            $name = $attribute->getName();

            if (! in_array($name, [A\GlobalSearch::class, A\GlobalFilter::class, A\GlobalOrder::class, A\WithoutGlobalSearch::class, A\WithoutGlobalFilter::class, A\WithoutGlobalOrder::class, A\MultiOrder::class, A\SearchGrouping::class, A\Query::class, A\Actions::class, A\ActionAuthorize::class], true)) {
                continue;
            }
            $a = $attribute->newInstance();

            if ($a instanceof A\GlobalSearch) {
                $add('global.search', $a->targets);
            } elseif ($a instanceof A\WithoutGlobalSearch) {
                $add('global.search', null);
            } elseif ($a instanceof A\GlobalFilter) {
                if ($a->id === null || $a->apply === null) {
                    throw DapterException::configuration();
                }$add('global.filter.'.$a->id, new GlobalFilterDefinition((new TrustedHandlerBinder)->filterDomain($a->values, $a->apply, $a->options), $a->apply, $a->multiple, $a->empty, $a->valueType, $a->options));
            } elseif ($a instanceof A\GlobalOrder) {
                if ($a->id === null || $a->apply === null) {
                    throw DapterException::configuration();
                }$add('global.order.'.$a->id, new GlobalOrderDefinition($a->label ?? $a->id, $a->apply, $a->options));
            } elseif ($a instanceof A\WithoutGlobalFilter) {
                $add('global.filter.'.$a->id, null);
            } elseif ($a instanceof A\WithoutGlobalOrder) {
                $add('global.order.'.$a->id, null);
            } elseif ($a instanceof A\MultiOrder) {
                $add('multi_order', [$a->enabled, $a->max]);
            } elseif ($a instanceof A\SearchGrouping) {
                $add('search_match', [$a->default, $a->allowed]);
            } elseif ($a instanceof A\Query) {
                $add('query', $a->options);
            } elseif ($a instanceof A\Actions) {
                $add('actions_class', $a->actionsClass);
            } elseif ($a instanceof A\ActionAuthorize) {
                if ($a->id === null) {
                    throw DapterException::configuration();
                }$add('action_policy.'.$a->id, new ActionPolicy($a->ability, $a->arguments, $a->target));
            }
        }
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            $action = $method->getAttributes(A\Action::class);

            if ($action !== []) {
                $id = $action[0]->newInstance()->id ?? $method->getName();
                foreach ($method->getAttributes(A\ActionAuthorize::class) as $attribute) {
                    $a = $attribute->newInstance();
                    $add('action_policy.'.$id, new ActionPolicy($a->ability, $a->arguments, $a->target));
                }
            }
            $filters = $method->getAttributes(A\GlobalFilter::class);
            $orders = $method->getAttributes(A\GlobalOrder::class);

            if (($filters !== [] || $orders !== []) && $method->getAttributes(A\Action::class) !== []) {
                throw DapterException::configuration();
            }
            foreach ([...$filters, ...$orders] as $attribute) {
                $a = $attribute->newInstance();

                if ($a->apply !== null) {
                    throw DapterException::configuration();
                }
                $apply = (new TrustedHandlerBinder)->method($source, $method);
                $id = $a->id ?? $method->getName();

                if ($a instanceof A\GlobalFilter) {
                    $add('global.filter.'.$id, new GlobalFilterDefinition((new TrustedHandlerBinder)->filterDomain($a->values, $apply, $a->options), $apply, $a->multiple, $a->empty, $a->valueType, $a->options));
                } else {
                    $add('global.order.'.$id, new GlobalOrderDefinition($a->label ?? $id, $apply, $a->options));
                }
            }
        }

        return $values;
    }

    /** @param array<string,mixed> $values
     * @return array{GlobalDefinition,bool,int,SearchMatch,list<SearchMatch>,?QueryOptions,class-string|null,array<string,ActionPolicy|null>,bool}
     */
    public function resolve(array $values): array
    {
        $search = $values['global.search'] ?? null;
        $filters = [];
        $orders = [];
        $policies = [];

        if ($search !== null) {
            if (! is_array($search) || array_is_list($search)) {
                throw DapterException::configuration();
            }foreach ($search as $id => $target) {
                if ($target instanceof SearchTarget) {
                    FieldOptionsResolver::fullText($target->matchType === MatchType::FullText, $target->fullText);
                }

                if (! is_string($id) || ! FieldOptionsResolver::identifier($id) || ! $target instanceof SearchTarget || ! self::target($target->target) || $target->matchType === MatchType::Vector || $target->valueType === ValueType::Boolean || $target->valueType !== ValueType::String && $target->matchType !== MatchType::Equals) {
                    throw DapterException::configuration();
                }
            }
        }
        foreach ($values as $key => $d) {
            if (str_starts_with($key, 'global.filter.') || str_starts_with($key, 'global.order.') || str_starts_with($key, 'action_policy.')) {
                $id = substr($key, strrpos($key, '.') + 1);

                if (! FieldOptionsResolver::identifier($id)) {
                    throw DapterException::configuration();
                }

                if ($d === null) {
                    if (str_starts_with($key, 'action_policy.')) {
                        $policies[$id] = null;
                    }

                    continue;
                }

                if (str_starts_with($key, 'global.filter.')) {
                    if (! $d instanceof GlobalFilterDefinition) {
                        throw DapterException::configuration();
                    }$domain = TrustedHandlerBinder::domain($d->values);

                    if ($d->valueType !== null) {
                        $domain = array_map(fn (mixed $v): string|int|float|bool|null => ScalarValue::normalize($v, $d->valueType, true), $domain);
                    }
                    $handler = (new TrustedHandlerBinder)->describe($d->apply);
                    $this->validateHandler($d->options);
                    foreach ($domain as $v) {
                        try {
                            (new TrustedHandlerBinder)->bindValue($handler, $d->multiple ? [$v] : $v, $d->options);
                        } catch (DapterException) {
                            throw DapterException::configuration();
                        }
                    }
                    $filters[$id] = new GlobalFilterDefinition($domain, $d->apply, $d->multiple, $d->empty, $d->valueType, $d->options);
                } elseif (str_starts_with($key, 'global.order.')) {
                    if (! $d instanceof GlobalOrderDefinition || $d->label === '') {
                        throw DapterException::configuration();
                    }(new TrustedHandlerBinder)->describe($d->apply);
                    $this->validateHandler($d->options);
                    $orders[$id] = $d;
                } else {
                    if (! $d instanceof ActionPolicy || $d->ability === '') {
                        throw DapterException::configuration();
                    }$this->validateTarget($d->target);
                    $policies[$id] = $d;
                }
            }
        }
        [$multi,$max] = $values['multi_order'] ?? [false, 1];

        if (! is_bool($multi) || ! is_int($max) || $max < 1) {
            throw DapterException::configuration();
        }
        [$default,$allowed] = $values['search_match'] ?? [SearchMatch::All, [SearchMatch::All]];

        if (! $default instanceof SearchMatch || ! is_array($allowed) || ! array_is_list($allowed) || $allowed === [] || ! in_array($default, $allowed, true) || count(array_unique(array_map(fn (mixed $a): string => $a instanceof SearchMatch ? $a->value : throw DapterException::configuration(), $allowed))) !== count($allowed)) {
            throw DapterException::configuration();
        }
        $exactCounts = array_key_exists('exact_counts', $values) ? $values['exact_counts'] : false;

        if (! is_bool($exactCounts)) {
            throw DapterException::configuration();
        }
        $query = $values['query'] ?? null;

        if ($query !== null) {
            $this->validateQuery($query);
        }
        $actions = $values['actions_class'] ?? null;

        if ($actions !== null && (! is_string($actions) || ! class_exists($actions))) {
            throw DapterException::configuration();
        }

        return [new GlobalDefinition($search, $filters, $orders), $multi, $max, $default, $allowed, $query, $actions, $policies, $exactCounts];
    }

    /** @param array<string,mixed> $values */
    public static function strictLiteralSearch(array $values): bool
    {
        $strict = $values['strict_literal_search'] ?? false;

        if (! is_bool($strict) || array_key_exists('strict_literal_search', $values) && $values['strict_literal_search'] === null) {
            throw DapterException::configuration();
        }

        return $strict;
    }

    private function validateQuery(QueryOptions $query): void
    {
        if ($query->identity !== null) {
            if ($query->identity->columns === []) {
                throw DapterException::configuration();
            }$seen = [];
            foreach ($query->identity->columns as $c) {
                if (! self::target($c->target) || $c->output === '' || isset($seen[$c->output])) {
                    throw DapterException::configuration();
                }$seen[$c->output] = true;
            }
        }
        $seen = [];
        foreach ($query->fixedOrder as $term) {
            if (! FieldOptionsResolver::identifier($term->field) || isset($seen[$term->field])) {
                throw DapterException::configuration();
            }$seen[$term->field] = true;
        }

        if ($query->counts !== null) {
            (new TrustedHandlerBinder)->describe($query->counts);
        }
    }

    private function validateTarget(?RecordTarget $target): void
    {
        if ($target !== null && (! FieldOptionsResolver::identifier($target->input) || ! in_array($target->valueType, [ValueType::String, ValueType::Integer], true) || $target->target !== null && ! self::target($target->target) || $target->output === '')) {
            throw DapterException::configuration();
        }
    }

    private function validateHandler(?HandlerOptions $options): void
    {
        $b = $options?->binding;

        if ($b !== null && ! enum_exists($b->enum)) {
            throw DapterException::configuration();
        }
        $r = $options?->requires;

        if ($r === null) {
            return;
        }

        if ($r->sourceType !== null && ! is_a($r->sourceType, DataSource::class, true) || array_diff($r->databases, ['sqlite', 'mysql', 'pgsql']) !== [] || $r->driver !== null && ! FieldOptionsResolver::identifier($r->driver)) {
            throw DapterException::configuration();
        }
    }

    public static function target(string $target): bool
    {
        return (bool) preg_match('/\A[A-Za-z_][A-Za-z_0-9]*(?:\.[A-Za-z_][A-Za-z_0-9]*)*\z/D', $target);
    }

    private function primitives(mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $v) {
                $this->primitives($v);
            }
        } elseif (! is_scalar($value) && $value !== null) {
            throw DapterException::configuration();
        }
    }

    /** @param array<array-key,mixed> $value
     * @param  list<string>  $allowed
     */
    private function keys(array $value, array $allowed): void
    {
        if (array_diff(array_keys($value), $allowed) !== []) {
            throw DapterException::configuration();
        }
    }

    /** @param array<string,mixed>|null $d */
    private function fieldOptions(?array $d): ?FieldOptions
    {
        if ($d === null) {
            return null;
        }$this->keys($d, ['output', 'locale', 'fullText', 'vector']);
        $locale = $d['locale'] ?? null;

        if ($locale !== null) {
            $this->keys($locale, ['locale']);

            if (! is_string($locale['locale'] ?? null)) {
                throw DapterException::configuration();
            }
        }

        $vector = $d['vector'] ?? null;

        if ($vector !== null) {
            $this->keys($vector, ['dimensions', 'maxDistance']);

            if (! is_int($vector['dimensions'] ?? null) || (! is_int($vector['maxDistance'] ?? null) && ! is_float($vector['maxDistance'] ?? null))) {
                throw DapterException::configuration();
            }
        }

        return new FieldOptions($d['output'] ?? null, $locale === null ? null : new LocaleOptions($locale['locale']), $this->fullText($d['fullText'] ?? null), $vector === null ? null : new VectorOptions($vector['dimensions'], $vector['maxDistance']));
    }

    /** @param array<string,mixed>|null $d */
    private function fullText(?array $d): ?FullTextOptions
    {
        if ($d === null) {
            return null;
        }
        $this->keys($d, ['configuration']);

        if (! is_string($d['configuration'] ?? null)) {
            throw DapterException::configuration();
        }

        return new FullTextOptions($d['configuration']);
    }

    /** @param array<string,mixed>|null $d */
    private function handlerOptions(?array $d): ?HandlerOptions
    {
        if ($d === null) {
            return null;
        }$this->keys($d, ['binding', 'requires']);
        $b = $d['binding'] ?? null;
        $r = $d['requires'] ?? null;

        if ($b !== null) {
            $this->keys($b, ['enum', 'elements']);
        }

        if ($r !== null) {
            $this->keys($r, ['sourceType', 'databases', 'driver']);
        }

        return new HandlerOptions($b === null ? null : new EnumBinding($b['enum'], $b['elements'] ?? false), $r === null ? null : new HandlerRequirements($r['sourceType'] ?? null, $r['databases'] ?? [], $r['driver'] ?? null));
    }

    /** @param array<string,mixed> $d */
    private function policy(array $d): ActionPolicy
    {
        $this->keys($d, ['ability', 'arguments', 'target']);
        $t = $d['target'] ?? null;

        if ($t !== null) {
            $this->keys($t, ['input', 'valueType', 'target', 'output']);
        }

        return new ActionPolicy($d['ability'], $d['arguments'] ?? [], $t === null ? null : new RecordTarget($t['input'], ValueType::from($t['valueType'] ?? 'string'), $t['target'] ?? null, $t['output'] ?? null));
    }
}
