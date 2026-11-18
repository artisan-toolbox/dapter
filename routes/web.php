<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Http\ActionController;
use ArtisanToolbox\Dapter\Http\DataController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')->post('dapter/data/{source}', DataController::class)->name('dapter.data');

Route::middleware('web')->match(['POST', 'PUT', 'PATCH', 'DELETE'], 'dapter/actions/{source}/{action}/{target?}', ActionController::class)->where('target', '.*')->name('dapter.action');
