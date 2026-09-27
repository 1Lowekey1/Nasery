<?php

use App\Http\Controllers\Api\V1\Analytics\AnalyticsController;
use Illuminate\Support\Facades\Route;

/*
| B15 organizer analytics (overview with bid and event performance; live
| event-day numbers).
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified', 'account:business'])
    ->where(['organization' => $uuid])
    ->prefix('organizations/{organization}/analytics')->name('organizations.analytics.')
    ->group(function () {
        Route::get('/', [AnalyticsController::class, 'overview'])->name('overview');
        Route::get('live', [AnalyticsController::class, 'live'])->name('live');
    });
