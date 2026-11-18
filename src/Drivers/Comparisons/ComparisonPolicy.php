<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers\Comparisons;

use ArtisanToolbox\Dapter\Drivers\ReadSelection;
use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\FieldDefinition;
use ArtisanToolbox\Dapter\Options\OrderDefinition;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use ArtisanToolbox\Dapter\Options\SearchDefinition;
use Closure;
use Collator;
use Locale;
use PDO;
use ResourceBundle;
use Throwable;

/** @internal */
final class ComparisonPolicy
{
    /**
     * @param  list<SortType>  $sortTypes
     * @param  (Closure(): void)|null  $bindingGuard
     */
    private function __construct(private readonly string $engine, private readonly ?PDO $pdo = null, private readonly array $sortTypes = [], private readonly ?Closure $bindingGuard = null) {}

    /** @var array<string,mixed>|null */
    private ?array $executionIdentity = null;

    private bool $requiresExecutionGuard = false;

    private bool $literalExecutionGuard = false;

    public function requiresExecutionGuard(): bool
    {
        return $this->requiresExecutionGuard || $this->literalExecutionGuard;
    }

    public function guard(): void
    {
        if ($this->requiresExecutionGuard && $this->identity() !== $this->executionIdentity) {
            throw DapterException::configuration();
        }
    }

    /** @return array<string,mixed> */
    private function identity(): array
    {
        $statement = $this->pdo->query("select current_user as role, pg_catalog.current_database() as database, pg_catalog.current_setting('server_version_num') as version, pg_catalog.pg_backend_pid() as backend, pg_catalog.current_setting('search_path') as path");

        if ($statement === false) {
            throw DapterException::configuration();
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        if (count($rows) !== 1 || array_keys($rows[0]) !== ['role', 'database', 'version', 'backend', 'path'] || ! is_string($rows[0]['role']) || ! is_string($rows[0]['database']) || ! is_string($rows[0]['path']) || ! ctype_digit((string) $rows[0]['version']) || ! ctype_digit((string) $rows[0]['backend'])) {
            throw DapterException::configuration();
        }

        return $rows[0];
    }

    public static function collection(): self
    {
        return new self('collection');
    }

    /** @param (Closure(): void)|null $bindingGuard */
    public static function sql(string $engine, PDO $pdo, ?Closure $bindingGuard = null): self
    {
        return new self($engine, $pdo, bindingGuard: $bindingGuard);
    }

    /** @param list<SortType> $sortTypes */
    public static function legacy(array $sortTypes): self
    {
        return new self('legacy', sortTypes: $sortTypes);
    }

    public function resolve(ResolvedStoreOptions $options, ReadSelection $selection, bool $literalSearchPolicy = true): ResolvedComparisons
    {
        $this->bindingGuard?->__invoke();
        $unsupported = false;
        $fields = [];
        $locales = [];
        $fullText = [];
        $globalFullText = [];
        $vectors = [];
        $vectorSchema = null;
        $vectorProbed = false;
        $probes = [];
        foreach ($options->fields as $name => $field) {
            $search = $field->search;
            $order = $field->order;

            if ($search !== null && in_array(MatchType::Vector, $search->matchTypes, true)) {
                if (! $vectorProbed) {
                    $vectorSchema = $this->vector();
                    $vectorProbed = true;
                }

                if ($vectorSchema !== null) {
                    $vectors[$name] = $vectorSchema;
                } else {
                    foreach ($selection->searches as $clause) {
                        if ($clause->field === $name && $clause->matchType === MatchType::Vector) {
                            $unsupported = true;
                        }
                    }
                    $search = null;
                }
            }

            if ($search !== null && in_array(MatchType::FullText, $search->matchTypes, true)) {
                $configuration = $search->options->fullText->configuration;
                $available = $this->fullText($configuration, $probes);

                if ($available) {
                    $fullText[$name] = $configuration;
                } else {
                    foreach ($selection->searches as $clause) {
                        if ($clause->field === $name && $clause->matchType === MatchType::FullText) {
                            $unsupported = true;
                        }
                    }
                    $modes = array_values(array_filter($search->matchTypes, static fn (MatchType $type): bool => $type !== MatchType::FullText));
                    $search = $modes === [] ? null : new SearchDefinition($modes, $search->target, $search->valueType, $search->options);
                }
            }

            if ($order !== null) {
                $sorts = [];
                foreach ($order->sortTypes as $sort) {
                    $available = $sort === SortType::Locale
                        ? $this->locale($order->options->locale->locale, $name, $locales) && $options->mode !== PagingMode::Cursor
                        : $this->engine !== 'legacy' || in_array($sort, $this->sortTypes, true);

                    if ($available) {
                        $sorts[] = $sort;

                        continue;
                    }
                    foreach ($selection->order as $term) {
                        if ($term->field === $name && $term->sortType === $sort) {
                            $unsupported = true;
                        }
                    }
                    foreach ($options->query->fixedOrder ?? [] as $term) {
                        if ($term->field === $name && $term->sortType === $sort) {
                            throw DapterException::configuration();
                        }
                    }

                    if ($sort !== SortType::Locale) {
                        throw DapterException::configuration();
                    }
                    unset($locales[$name]);
                }

                if ($sorts !== $order->sortTypes) {
                    $order = $sorts === [] ? null : new OrderDefinition($sorts, $order->directions, $order->target, $order->valueType, $order->options);
                }
            }
            $fields[$name] = $search === $field->search && $order === $field->order
                ? $field
                : new FieldDefinition($field->target, $search, $order, $field->filters, $field->output);
        }
        foreach ($options->global->search ?? [] as $id => $target) {
            if ($target->matchType === MatchType::FullText) {
                $configuration = $target->fullText->configuration;

                if (! $this->fullText($configuration, $probes)) {
                    throw DapterException::configuration();
                }
                $globalFullText[$id] = $configuration;
            }
        }

        if ($unsupported) {
            throw DapterException::unsupported();
        }
        $admitted = $fields === $options->fields ? $options : new ResolvedStoreOptions($options->mode, $options->size, $options->sizes, $options->guard, $options->access, $options->authorization, $fields, $options->driver, $options->global, $options->multiOrder, $options->maxOrder, $options->searchMatch, $options->allowedSearchMatches, $options->query, $options->actionsClass, $options->actionPolicies, $options->exactCounts, $options->resource, $options->broadcasting, $options->strictLiteralSearch, $options->browserState);

        $literal = null;

        if (ResolvedLiteralSearch::declared($admitted)) {
            if (! $literalSearchPolicy) {
                throw DapterException::configuration();
            }

            try {
                $literal = ResolvedLiteralSearch::admit($this->engine, $admitted->strictLiteralSearch, $this->pdo);
            } catch (Throwable) {
                throw DapterException::configuration();
            }
            $this->literalExecutionGuard = $this->pdo !== null;
        }

        return new ResolvedComparisons($admitted, $locales, $fullText, $globalFullText, $literal, $vectors);
    }

    private function vector(): ?string
    {
        if ($this->engine !== 'pgsql') {
            return null;
        }
        $identity = $this->identity();

        if ($this->executionIdentity !== null && $this->executionIdentity !== $identity) {
            throw DapterException::configuration();
        }
        $this->executionIdentity = $identity;
        $catalog = $this->pdo->query("select n.nspname as schema, pg_catalog.has_schema_privilege(n.oid, 'USAGE') and pg_catalog.has_type_privilege(t.oid, 'USAGE') as admitted from pg_catalog.pg_extension e join pg_catalog.pg_namespace n on n.oid = e.extnamespace join pg_catalog.pg_type t on t.typnamespace = n.oid and t.typname = 'vector' join pg_catalog.pg_depend d on d.objid = t.oid and d.classid = 'pg_catalog.pg_type'::pg_catalog.regclass and d.refobjid = e.oid and d.refclassid = 'pg_catalog.pg_extension'::pg_catalog.regclass and d.deptype = 'e' where e.extname = 'vector'");

        if ($catalog === false) {
            throw DapterException::configuration();
        }
        $rows = $catalog->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return null;
        }

        if (count($rows) !== 1 || ! is_string($rows[0]['schema'] ?? null) || ! in_array($rows[0]['admitted'] ?? null, [true, 1, '1', 't'], true)) {
            throw DapterException::configuration();
        }
        $schema = $rows[0]['schema'];
        $quoted = '"'.str_replace('"', '""', $schema).'"';
        $probe = $this->pdo->prepare('select (?::'.$quoted.'.vector OPERATOR('.$quoted.'.<->) ?::'.$quoted.'.vector) as distance');

        if ($probe === false || ! $probe->execute(['[0,0,0]', '[1,0,0]']) || (float) $probe->fetchColumn() !== 1.0) {
            throw DapterException::configuration();
        }
        $this->requiresExecutionGuard = true;

        return $schema;
    }

    /** @param array<string,bool> $probes */
    private function fullText(string $configuration, array &$probes): bool
    {
        if ($this->engine !== 'pgsql') {
            return false;
        }

        if (array_key_exists($configuration, $probes)) {
            return $probes[$configuration];
        }
        $identity = $this->identity();

        if ($this->executionIdentity !== null && $this->executionIdentity !== $identity) {
            throw DapterException::configuration();
        }
        $this->executionIdentity = $identity;
        [$schema, $name] = explode('.', $configuration);
        $catalog = $this->pdo->prepare('select c.oid from pg_catalog.pg_ts_config c join pg_catalog.pg_namespace n on n.oid = c.cfgnamespace where n.nspname = ? and c.cfgname = ?');

        if ($catalog === false || ! $catalog->execute([strtolower($schema), strtolower($name)])) {
            throw DapterException::configuration();
        }
        $rows = $catalog->fetchAll(PDO::FETCH_ASSOC);

        if ($rows === []) {
            return $probes[$configuration] = false;
        }

        if (count($rows) !== 1 || ! isset($rows[0]['oid']) || ! ctype_digit((string) $rows[0]['oid'])) {
            throw DapterException::configuration();
        }
        $probe = $this->pdo->prepare("select (pg_catalog.to_tsvector(?::pg_catalog.regconfig, 'dapter') @@ pg_catalog.plainto_tsquery(?::pg_catalog.regconfig, 'dapter')) as admitted");

        if ($probe === false || ! $probe->execute([$configuration, $configuration])) {
            throw DapterException::configuration();
        }
        $result = $probe->fetchAll(PDO::FETCH_ASSOC);

        if (count($result) !== 1 || ! array_key_exists('admitted', $result[0]) || ! in_array($result[0]['admitted'], [true, false, 1, 0, '1', '0', 't', 'f'], true)) {
            throw DapterException::configuration();
        }

        $this->requiresExecutionGuard = true;

        return $probes[$configuration] = true;
    }

    /** @param array<string,array{collator:Collator,metadata:array<string,mixed>}> $locales */
    private function locale(string $requested, string $field, array &$locales): bool
    {
        if (! class_exists(Collator::class) || ! class_exists(ResourceBundle::class)) {
            return false;
        }
        $canonical = Locale::canonicalize($requested);
        $available = ResourceBundle::getLocales('');

        if ($canonical === null || $available === false || ! in_array($canonical, $available, true)) {
            throw DapterException::configuration();
        }
        $collator = Collator::create($canonical);

        if ($collator === null || ! $collator->setStrength(Collator::TERTIARY) || ! $collator->setAttribute(Collator::NORMALIZATION_MODE, Collator::ON) || ! $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::OFF)) {
            throw DapterException::configuration();
        }
        $valid = $collator->getLocale(Locale::VALID_LOCALE);
        $actual = $collator->getLocale(Locale::ACTUAL_LOCALE);

        if ($valid === '' || $actual === '' || Locale::getPrimaryLanguage($valid) !== Locale::getPrimaryLanguage($canonical)) {
            throw DapterException::configuration();
        }

        if ($this->engine !== 'collection') {
            return false;
        }
        $locales[$field] = ['collator' => $collator, 'metadata' => ['locale' => $canonical, 'validLocale' => $valid, 'actualLocale' => $actual, 'icuVersion' => INTL_ICU_VERSION, 'strength' => 'tertiary', 'normalization' => true, 'numeric' => false]];

        return true;
    }
}
