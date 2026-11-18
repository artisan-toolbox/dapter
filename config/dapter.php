<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Enums\PagingMode;

return [
    'broadcasting' => ['context' => null, 'queue_connection' => null, 'queue' => null],
    'declarations' => ['strict_literal_search' => false],
    'access' => ['guard' => 'web'],
    'paging' => [
        'mode' => PagingMode::Offset,
        'exact_counts' => false,
        'per_page' => 10,
        'per_page_options' => [5, 10, 50, 100],
    ],
];
