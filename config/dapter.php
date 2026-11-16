<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Enums\PagingMode;

return [
    'access' => ['guard' => 'web'],
    'paging' => [
        'mode' => PagingMode::Offset,
        'per_page' => 10,
        'per_page_options' => [5, 10, 50, 100],
    ],
];
