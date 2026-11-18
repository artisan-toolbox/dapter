<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Paging;

use ArtisanToolbox\Dapter\Contracts\DataSource;
use ArtisanToolbox\Dapter\Contracts\WithCursorScope;
use ArtisanToolbox\Dapter\Drivers\OrderTerm;
use ArtisanToolbox\Dapter\Drivers\ReadSelection;
use ArtisanToolbox\Dapter\Enums\ErrorCode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\CursorRequest;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\ResolvedStoreOptions;
use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\StringEncrypter;
use ReflectionFunction;
use stdClass;
use Throwable;
use UnitEnum;

/** @internal */
final class CursorAccess
{
    public const int LIFETIME = 900;

    public const int MAX_TOKEN_BYTES = 16384;

    /** @param array<string,mixed>|null $envelope */
    private function __construct(private readonly StringEncrypter $encrypter, private readonly string $requestFingerprint, private readonly ?array $envelope) {}

    public static function forSource(string $alias, DataSource $source, ResolvedStoreOptions $options, ReadSelection $selection, CursorRequest $paging, ?Authenticatable $user): self
    {
        if (! $source instanceof WithCursorScope || ($scope = $source->cursorScope()) === '') {
            throw DapterException::configuration();
        }
        $encrypter = resolve('encrypter');

        if (! $encrypter instanceof StringEncrypter) {
            throw DapterException::configuration();
        }
        $fingerprint = StableFingerprint::of([
            'source' => [$alias, $source::class], 'scope' => $scope,
            'principal' => [$options->guard, $user === null ? null : [$user::class, $user->getAuthIdentifierName(), $user->getAuthIdentifier()]],
            'fields' => self::declaration($selection->fields), 'global' => self::declaration($selection->requestedGlobal($options)),
            'match' => $selection->searchMatch, 'order' => self::declaration($selection->order), 'preset' => $selection->preset,
            'size' => $paging->perPage, 'definitions' => self::declaration([$options->fields, $options->global, $options->query, $options->exactCounts, $options->strictLiteralSearch]),
        ]);
        $envelope = $paging->cursor === null ? null : self::decode($encrypter, $paging->cursor);

        if ($envelope !== null && ! hash_equals($fingerprint, $envelope['request'])) {
            throw self::invalid();
        }

        return new self($encrypter, $fingerprint, $envelope);
    }

    public function assertBaseScope(string $fingerprint): void
    {
        if ($this->envelope !== null && ! hash_equals($this->envelope['base'], $fingerprint)) {
            throw self::invalid();
        }
    }

    public function accept(CursorExecutionContext $context): ?CursorBoundary
    {
        $this->assertBaseScope($context->baseScopeFingerprint);

        if ($this->envelope === null) {
            return null;
        }
        $envelope = $this->envelope;

        if (! hash_equals($envelope['selected'], $context->selectedScopeFingerprint) || ! hash_equals($envelope['order'], self::orderFingerprint($context)) || count($envelope['values']) !== count($context->order)) {
            throw self::invalid();
        }

        if (! self::carriers($envelope['values'], $context)) {
            throw self::invalid();
        }

        throw_if($envelope['expires'] <= now()->getTimestamp(), DapterException::class, ErrorKind::CursorExpired, ErrorCode::CursorExpired, 409);

        return new CursorBoundary($envelope['values'], $envelope['forward']);
    }

    public function seal(CursorBoundary $boundary, CursorExecutionContext $context): string
    {
        if (! self::tuple($boundary->values) || count($boundary->values) !== count($context->order) || ! self::carriers($boundary->values, $context)) {
            throw DapterException::unsupported();
        }
        $issued = now()->getTimestamp();
        $token = $this->encrypter->encryptString(json_encode([
            'purpose' => 'dapter.cursor', 'version' => 1, 'issued' => $issued, 'expires' => $issued + self::LIFETIME,
            'request' => $this->requestFingerprint, 'base' => $context->baseScopeFingerprint,
            'selected' => $context->selectedScopeFingerprint, 'order' => self::orderFingerprint($context),
            'forward' => $boundary->forward, 'values' => $boundary->values,
        ], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        if (strlen($token) > self::MAX_TOKEN_BYTES) {
            throw DapterException::unsupported();
        }

        return $token;
    }

    private static function orderFingerprint(CursorExecutionContext $context): string
    {
        return StableFingerprint::of(array_map(static fn (OrderTerm $term): array => [$term->field, $term->target, $term->output, $term->valueType, $term->direction, $term->sortType, $term->role], $context->order));
    }

    /** @return array<string,mixed> */
    private static function decode(StringEncrypter $encrypter, string $token): array
    {
        if ($token === '' || strlen($token) > self::MAX_TOKEN_BYTES) {
            throw self::invalid();
        }

        try {
            $json = $encrypter->decryptString($token);
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
            preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"\s*:/s', $json, $matches);
            $names = array_map(static fn (string $key): mixed => json_decode('"'.$key.'"', flags: JSON_THROW_ON_ERROR), $matches[1]);

            if (count(array_unique($names)) !== count($names)) {
                throw self::invalid();
            }
        } catch (Throwable) {
            throw self::invalid();
        }
        $keys = ['purpose', 'version', 'issued', 'expires', 'request', 'base', 'selected', 'order', 'forward', 'values'];

        if (! is_array($value) || count($value) !== count($keys) || array_diff($keys, array_keys($value)) !== [] || $value['purpose'] !== 'dapter.cursor' || $value['version'] !== 1 || ! is_int($value['issued']) || ! is_int($value['expires']) || $value['issued'] < 0 || $value['issued'] > now()->getTimestamp() || $value['expires'] - $value['issued'] !== self::LIFETIME || ! is_bool($value['forward']) || ! self::tuple($value['values'])) {
            throw self::invalid();
        }
        foreach (['request', 'base', 'selected', 'order'] as $key) {
            if (! is_string($value[$key]) || ! preg_match('/\A[a-f0-9]{64}\z/D', $value[$key])) {
                throw self::invalid();
            }
        }

        return $value;
    }

    private static function tuple(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values) || $values === []) {
            return false;
        }
        foreach ($values as $value) {
            if (! is_string($value) && ! is_bool($value) && ! is_int($value) && ! (is_float($value) && is_finite($value))) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string|int|float|bool> $values */
    private static function carriers(array $values, CursorExecutionContext $context): bool
    {
        foreach ($context->order as $index => $term) {
            $value = $values[$index];
            $valid = match ($term->valueType) {
                ValueType::String => is_string($value),
                ValueType::Integer => is_int($value) || is_string($value) && preg_match('/\A-?(?:0|[1-9][0-9]*)\z/D', $value),
                ValueType::Number => is_int($value) || is_float($value) && is_finite($value) || is_string($value) && preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?\z/D', $value),
                ValueType::Boolean => is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1',
                null => false,
            };

            if (! $valid) {
                return false;
            }
        }

        return true;
    }

    private static function invalid(): DapterException
    {
        return new DapterException(ErrorKind::Validation, ErrorCode::InvalidCursor, 422);
    }

    private static function declaration(mixed $value): mixed
    {
        if ($value instanceof UnitEnum) {
            return [$value::class, $value instanceof BackedEnum ? $value->value : $value->name];
        }

        if ($value instanceof Closure) {
            $reflection = new ReflectionFunction($value);

            return ['handler', $reflection->getFileName(), $reflection->getStartLine(), $reflection->getEndLine()];
        }

        if (is_array($value)) {
            return array_map(self::declaration(...), $value);
        }

        if ($value instanceof stdClass || is_object($value) && str_starts_with($value::class, 'ArtisanToolbox\\Dapter\\Options\\') || $value instanceof OrderTerm) {
            return ['type' => $value::class, 'properties' => self::declaration(get_object_vars($value))];
        }

        return $value;
    }
}
