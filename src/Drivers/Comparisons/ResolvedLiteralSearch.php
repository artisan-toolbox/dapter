<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Drivers\Comparisons;

use ArtisanToolbox\Dapter\Enums\MatchType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use PDO;
use Pdo\Sqlite;
use Symfony\Polyfill\Intl\Normalizer\Normalizer;

/** @internal */
final readonly class ResolvedLiteralSearch
{
    private function __construct(private string $engine, private bool $strict) {}

    public static function declared(ResolvedStoreOptions $options): bool
    {
        foreach ($options->fields as $field) {
            if ($field->search?->valueType === ValueType::String && array_filter($field->search->matchTypes, static fn (MatchType $type): bool => ! in_array($type, [MatchType::FullText, MatchType::Vector], true)) !== []) {
                return true;
            }
        }
        foreach ($options->global->search ?? [] as $target) {
            if ($target->valueType === ValueType::String && $target->matchType !== MatchType::FullText) {
                return true;
            }
        }

        return false;
    }

    public static function admit(string $engine, bool $strict, ?PDO $pdo): self
    {
        $literal = new self($engine, $strict);

        if (in_array($engine, ['collection', 'legacy'], true)) {
            if (! $strict && self::fold("JOA\u{0303}O") !== 'joao') {
                throw DapterException::configuration();
            }

            return $literal;
        }

        if ($pdo === null || ! in_array($engine, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw DapterException::configuration();
        }

        if ($engine === 'sqlite' && ! $strict) {
            if (! $pdo instanceof Sqlite || ! $pdo->createFunction('dapter_literal_fold_v1', static fn (?string $value): ?string => $value === null ? null : self::fold($value), 1, Sqlite::DETERMINISTIC)) {
                throw DapterException::configuration();
            }
        }

        if ($engine === 'pgsql') {
            $encoding = $pdo->query("select pg_catalog.current_setting('server_encoding') as encoding");

            if ($encoding === false || $encoding->fetchColumn() !== 'UTF8') {
                throw DapterException::configuration();
            }
        }
        foreach ([['João', 'joao', ! $strict], ["JOA\u{0303}O", 'joao', ! $strict], ['João ', 'João', false], ['Straße', 'Straße', true], ['!%_', '!%_', true]] as [$value, $input, $expected]) {
            $statement = $pdo->prepare('select '.$literal->predicate('?', MatchType::Equals).' as admitted');

            if ($statement === false || ! $statement->execute([$value, ...$literal->bindings($input, MatchType::Equals)])) {
                throw DapterException::configuration();
            }
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);

            if (count($result) !== 1 || ! array_key_exists('admitted', $result[0]) || ! in_array($result[0]['admitted'], [true, false, 1, 0, '1', '0', 't', 'f'], true) || in_array($result[0]['admitted'], [true, 1, '1', 't'], true) !== $expected) {
                throw DapterException::configuration();
            }
        }

        return $literal;
    }

    public static function fold(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw DapterException::configuration();
        }
        $normalized = Normalizer::normalize($value, Normalizer::FORM_D);

        if ($normalized === false) {
            throw DapterException::configuration();
        }
        $unmarked = preg_replace('/'.UnicodeMarks::PATTERN.'/u', '', $normalized);

        if ($unmarked === null) {
            throw DapterException::configuration();
        }

        return mb_strtolower($unmarked, 'UTF-8');
    }

    public function matches(string $value, string $input, MatchType $type): bool
    {
        if (preg_match('//u', $value) !== 1 || preg_match('//u', $input) !== 1) {
            throw DapterException::configuration();
        }
        [$value, $input] = $this->strict ? [$value, $input] : [self::fold($value), self::fold($input)];

        return match ($type) {
            MatchType::Equals => $value === $input,
            MatchType::Contains => str_contains($value, $input),
            MatchType::StartsWith => str_starts_with($value, $input),
            MatchType::EndsWith => str_ends_with($value, $input),
            MatchType::FullText, MatchType::Vector => throw DapterException::configuration(),
        };
    }

    public function expression(string $column, MatchType $type): Expression
    {
        return new class($this, $column, $type) implements Expression
        {
            public function __construct(private readonly ResolvedLiteralSearch $literal, private readonly string $column, private readonly MatchType $type) {}

            public function getValue(Grammar $grammar): string
            {
                return $this->literal->predicate($grammar->wrap($this->column), $this->type);
            }
        };
    }

    public function predicate(string $column, MatchType $type): string
    {
        if ($this->engine === 'sqlite') {
            $value = 'CAST('.($this->strict ? $column : 'dapter_literal_fold_v1('.$column.')').' AS BLOB)';

            // SQLite substr returns NULL for empty BLOBs, so empty needles need an explicit branch.
            return match ($type) {
                MatchType::Equals => $value.' = CAST(? AS BLOB)',
                MatchType::Contains => 'instr('.$value.', CAST(? AS BLOB)) > 0',
                MatchType::StartsWith => 'CASE WHEN length(CAST(? AS BLOB)) = 0 THEN length('.$value.') >= 0 ELSE substr('.$value.', 1, length(CAST(? AS BLOB))) = CAST(? AS BLOB) END',
                MatchType::EndsWith => 'CASE WHEN length(CAST(? AS BLOB)) = 0 THEN length('.$value.') >= 0 ELSE substr('.$value.', length('.$value.') - length(CAST(? AS BLOB)) + 1) = CAST(? AS BLOB) END',
                MatchType::FullText, MatchType::Vector => throw DapterException::configuration(),
            };
        }
        $value = $this->operand($column);
        $input = $this->operand('?');

        return $value.' LIKE '.$input." ESCAPE '!'";
    }

    private function operand(string $value): string
    {
        return match ($this->engine) {
            'pgsql' => $this->strict ? $value.' COLLATE pg_catalog."C"' : "pg_catalog.regexp_replace(pg_catalog.lower(pg_catalog.normalize($value, 'NFD')), ?, '', 'g') COLLATE pg_catalog.\"C\"",
            'mysql' => $this->strict ? 'CAST('.$value.' AS BINARY)' : "REGEXP_REPLACE(CONVERT($value USING utf8mb4), ?, '') COLLATE utf8mb4_0900_ai_ci",
            default => throw DapterException::configuration(),
        };
    }

    /** @return list<string> */
    public function bindings(string $input, MatchType $type): array
    {
        if ($this->engine === 'sqlite') {
            $input = $this->strict ? $input : self::fold($input);

            return in_array($type, [MatchType::StartsWith, MatchType::EndsWith], true) ? [$input, $input, $input] : [$input];
        }
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $input);
        $pattern = match ($type) {
            MatchType::Contains => '%'.$escaped.'%',
            MatchType::Equals => $escaped,
            MatchType::StartsWith => $escaped.'%',
            MatchType::EndsWith => '%'.$escaped,
            MatchType::FullText, MatchType::Vector => throw DapterException::configuration(),
        };

        return $this->strict ? [$pattern] : [UnicodeMarks::PATTERN, $pattern, UnicodeMarks::PATTERN];
    }
}
