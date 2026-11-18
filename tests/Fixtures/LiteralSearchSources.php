<?php

declare(strict_types=1);

namespace App\DataSources;

use ArtisanToolbox\Dapter\Attributes\StrictLiteralSearch;

#[StrictLiteralSearch]
class LiteralStrictParent extends OptionRecords {}

final class LiteralInheritedStrict extends LiteralStrictParent {}

#[StrictLiteralSearch(false)]
final class LiteralInheritedFolded extends LiteralStrictParent {}
