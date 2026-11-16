<?php

declare(strict_types=1);

namespace App\DataSources;

use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Http\DapterException;

final class ProtocolRecords extends OptionRecords
{
    public static ?string $failureContext = null;

    public function source(): array
    {
        if (self::$failureContext !== null) {
            self::$reads++;

            throw self::$failureContext === 'custom422'
                ? new DapterException(ErrorKind::Server, 'custom/source:unavailable', 422)
                : DapterException::configuration();
        }

        return parent::source();
    }
}
