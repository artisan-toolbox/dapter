<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Support;

use ArtisanToolbox\Dapter\Contracts\RowIdentityProvider;
use ArtisanToolbox\Dapter\Drivers\SqlIdentitySelector;
use ArtisanToolbox\Dapter\Http\DapterException;
use Illuminate\Database\Eloquent\Model;

/** @internal */
final readonly class FieldIdentity implements RowIdentityProvider
{
    public function __construct(private ?string $field = null, private ?SqlIdentitySelector $sql = null) {}

    public function identify(mixed $rawRow): int|string
    {
        $field = $this->field ?? ($rawRow instanceof Model ? $rawRow->getKeyName() : 'id');

        if ($rawRow instanceof Model) {
            if ($this->field === null && (! array_key_exists($field, $rawRow->toArray()))) {
                throw DapterException::configuration();
            }

            return RowIdentity::validate($rawRow->getAttribute($field));
        }

        return RowIdentity::validate(RowValue::output($rawRow, $field));
    }

    public function selector(): ?SqlIdentitySelector
    {
        return $this->sql;
    }

    public function lookup(int|string $publicIdentifier): int|string
    {
        return $publicIdentifier;
    }
}
