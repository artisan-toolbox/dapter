<?php

declare(strict_types=1);

use App\DataSources\KernelPagingRecords;
use ArtisanToolbox\Dapter\Drivers\OrderTerm;
use ArtisanToolbox\Dapter\Drivers\ReadSelection;
use ArtisanToolbox\Dapter\Enums\ErrorCode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Enums\PagingMode;
use ArtisanToolbox\Dapter\Enums\SortDirection;
use ArtisanToolbox\Dapter\Enums\SortType;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Http\CursorRequest;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Paging\CursorAccess;
use ArtisanToolbox\Dapter\Paging\CursorBoundary;
use ArtisanToolbox\Dapter\Paging\CursorExecutionContext;
use ArtisanToolbox\Dapter\Paging\StableFingerprint;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\StringEncrypter;
use Illuminate\Encryption\Encrypter;

require_once __DIR__.'/../Fixtures/KernelPagingSources.php';

function kernelCursorAccess(?string $token = null, string $alias = 'KernelPagingRecords', ?Authenticatable $user = null, int $size = 5): CursorAccess
{
    $source = new KernelPagingRecords;
    $options = (new OptionsResolver)->resolve($source);

    return CursorAccess::forSource($alias, $source, $options, new ReadSelection, new CursorRequest($token, $size), $user);
}

function kernelCursorContext(string $base = 'base', string $selected = 'selected', ValueType $type = ValueType::Integer): CursorExecutionContext
{
    return new CursorExecutionContext(StableFingerprint::of($base), StableFingerprint::of($selected), [new OrderTerm('id', 'id', SortDirection::Asc, SortType::Native, $type, 'id', 'identity')]);
}

beforeEach(function (): void {
    KernelPagingRecords::$settings = StoreOptions::make()->publicAccess()->pagingMode(PagingMode::Cursor);
    KernelPagingRecords::$scope = 'public-test-v1';
    KernelPagingRecords::$reads = 0;
});

it('seals authenticated opaque JSON cursors and preserves raw decimal strings', function (): void {
    $context = kernelCursorContext(type: ValueType::Number);
    $access = kernelCursorAccess();
    expect($access->accept($context))->toBeNull();
    $token = $access->seal(new CursorBoundary(['123456789.123456789'], false), $context);
    expect($token)->not->toContain('123456789')->and(kernelCursorAccess($token)->accept($context))->toEqual(new CursorBoundary(['123456789.123456789'], false));
    expect(KernelPagingRecords::$reads)->toBe(0);
});

it('binds source principal scope size base selected query and complete order', function (string $change): void {
    $context = kernelCursorContext();
    $token = kernelCursorAccess()->seal(new CursorBoundary([1], true), $context);

    if ($change === 'scope') {
        KernelPagingRecords::$scope = 'other-tenant';
    }

    try {
        $access = kernelCursorAccess($token, $change === 'source' ? 'Other' : 'KernelPagingRecords', $change === 'user' ? new GenericUser(['id' => 1]) : null, $change === 'size' ? 10 : 5);

        if ($change === 'order') {
            $context = new CursorExecutionContext($context->baseScopeFingerprint, $context->selectedScopeFingerprint, [new OrderTerm('id', 'id', SortDirection::Desc, SortType::Native, ValueType::Integer, 'id')]);
        }
        $access->accept($change === 'base' ? kernelCursorContext('other') : ($change === 'selected' ? kernelCursorContext(selected: 'other') : $context));
        test()->fail('A mismatched cursor must be rejected.');
    } catch (DapterException $error) {
        expect($error->status)->toBe(422)->and($error->kind)->toBe(ErrorKind::Validation)->and($error->errorCode)->toBe(ErrorCode::InvalidCursor->value);
    }
})->with(['source', 'user', 'scope', 'size', 'base', 'selected', 'order']);

it('reports expiry only after complete same-context acceptance', function (): void {
    $context = kernelCursorContext();
    $token = kernelCursorAccess()->seal(new CursorBoundary([1], true), $context);
    $this->travel(900)->seconds();

    try {
        kernelCursorAccess($token)->accept($context);
        test()->fail('The expired cursor must require an explicit restart.');
    } catch (DapterException $error) {
        expect($error->status)->toBe(409)->and($error->kind)->toBe(ErrorKind::CursorExpired);
    }

    try {
        kernelCursorAccess($token)->accept(kernelCursorContext(selected: 'other'));
        test()->fail('A mismatched expired cursor must stay invalid.');
    } catch (DapterException $error) {
        expect($error->status)->toBe(422)->and($error->errorCode)->toBe('invalid_cursor');
    }
});

it('rejects unauthenticated oversized and malformed cursor envelopes', function (string $change): void {
    $token = kernelCursorAccess()->seal(new CursorBoundary([1], true), kernelCursorContext());
    $encrypter = resolve('encrypter');
    expect($encrypter instanceof StringEncrypter)->toBeTrue();
    $body = json_decode($encrypter->decryptString($token), true, flags: JSON_THROW_ON_ERROR);
    $body = match ($change) {
        'purpose' => [...$body, 'purpose' => 'another.token'],
        'version' => [...$body, 'version' => 2],
        'extra' => [...$body, 'extra' => true],
        'null' => [...$body, 'values' => [null]],
        'empty' => [...$body, 'values' => []],
        'tuple-map' => [...$body, 'values' => ['id' => 1]],
        'direction' => [...$body, 'forward' => 1],
        'lifetime' => [...$body, 'expires' => $body['issued'] + 901],
        'future' => [...$body, 'issued' => $body['issued'] + 10, 'expires' => $body['expires'] + 10],
        default => $body,
    };
    $token = match ($change) {
        'oversized' => str_repeat('a', 16385),
        'tampered' => substr($token, 0, -2).'xx',
        'json' => $encrypter->encryptString('{'),
        'duplicate' => $encrypter->encryptString(substr(json_encode($body, JSON_THROW_ON_ERROR), 0, -1).',"version":1}'),
        default => $encrypter->encryptString(json_encode($body, JSON_THROW_ON_ERROR)),
    };
    expect(fn () => kernelCursorAccess($token))->toThrow(DapterException::class);
})->with(['purpose', 'version', 'extra', 'null', 'empty', 'tuple-map', 'direction', 'lifetime', 'future', 'oversized', 'tampered', 'json', 'duplicate']);

it('uses application previous keys without serializing PHP objects', function (): void {
    $context = kernelCursorContext();
    $token = kernelCursorAccess()->seal(new CursorBoundary([1], true), $context);
    $old = resolve('encrypter');
    $rotated = (new Encrypter(random_bytes(32), 'AES-256-CBC'))->previousKeys([$old->getKey()]);
    app()->instance('encrypter', $rotated);
    expect(kernelCursorAccess($token)->accept($context)?->values)->toBe([1]);
    $rotated->previousKeys([]);
    expect(fn () => kernelCursorAccess($token))->toThrow(DapterException::class);
});

it('canonicalizes typed scalar maps and rejects arbitrary object fingerprints', function (): void {
    expect(StableFingerprint::of(['a' => 1, 'b' => true]))->toBe(StableFingerprint::of(['b' => true, 'a' => 1]))
        ->and(StableFingerprint::of([1]))->not->toBe(StableFingerprint::of(['1']));
    expect(fn () => StableFingerprint::of(new stdClass))->toThrow(DapterException::class);
});

it('rejects authenticated tuple carriers that do not match the bound order before expiry recovery', function (ValueType $type, mixed $value): void {
    $context = kernelCursorContext(type: $type);
    $valid = match ($type) {
        ValueType::String => 'alpha', ValueType::Integer => 1, ValueType::Number => '1.25', ValueType::Boolean => true,
    };
    $token = kernelCursorAccess()->seal(new CursorBoundary([$valid], true), $context);
    $encrypter = resolve('encrypter');
    $body = json_decode($encrypter->decryptString($token), true, flags: JSON_THROW_ON_ERROR);
    $body['values'] = [$value];
    $token = $encrypter->encryptString(json_encode($body, JSON_THROW_ON_ERROR));
    $this->travel(900)->seconds();

    try {
        kernelCursorAccess($token)->accept($context);
        test()->fail('Invalid tuple carriers cannot receive expiry recovery.');
    } catch (DapterException $error) {
        expect($error->status)->toBe(422)->and($error->errorCode)->toBe('invalid_cursor');
    }
})->with([[ValueType::String, 1], [ValueType::Integer, true], [ValueType::Integer, '1.25'], [ValueType::Number, false], [ValueType::Number, 'NaN'], [ValueType::Boolean, 2]]);
