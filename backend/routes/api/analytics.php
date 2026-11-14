<?php

use App\Http\Controllers\Api\V1\Analytics\AnalyticsController;
use App\Http\Controllers\Api\V1\Analytics\EventTrendsController;
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
    });

// Live events and the event types an organizer handles most (Home). Not under /analytics/:
// browser ad and tracker blockers drop requests whose path has an "/analytics/" segment.
Route::middleware(['auth:sanctum', 'active', 'verified', 'account:business'])
    ->where(['organization' => $uuid])
    ->prefix('organizations/{organization}')->name('organizations.')
    ->group(function () {
        Route::get('live-events', [AnalyticsController::class, 'live'])->name('live-events');
        Route::get('top-event-types', [AnalyticsController::class, 'eventTypes'])->name('top-event-types');
        Route::get('event-type-breakdown', [AnalyticsController::class, 'eventTypeBreakdown'])->name('event-type-breakdown');
    });

// Event trends across all of NaSeRy (counts only): organizer Home and the admin overview.
Route::middleware(['auth:sanctum', 'active', 'verified', 'account:business,admin'])
    ->get('event-trends', EventTrendsController::class)->name('event-trends');
