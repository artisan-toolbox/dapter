<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Enums\ErrorCode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Http\DapterException;

it('normalizes every package error code to the existing public string property', function (ErrorCode $code) {
    $exception = new DapterException(kind: ErrorKind::Server, errorCode: $code, status: 500);

    expect($exception->errorCode)->toBe($code->value)->and($exception->kind)->toBe(ErrorKind::Server)
        ->and($exception->status)->toBe(500)->and($exception->getMessage())->toBe('The data request could not be completed.');
})->with(ErrorCode::cases());

it('retains trusted custom error strings and existing named constructor arguments', function () {
    $exception = new DapterException(status: 409, errorCode: 'inventory_conflict', kind: ErrorKind::Validation);

    expect($exception->errorCode)->toBe('inventory_conflict')->and($exception->kind)->toBe(ErrorKind::Validation)->and($exception->status)->toBe(409);
});
