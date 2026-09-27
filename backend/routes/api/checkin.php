<?php

use App\Http\Controllers\Api\V1\CheckIn\CheckInController;
use App\Http\Controllers\Api\V1\CheckIn\WalkInController;
use Illuminate\Support\Facades\Route;

/*
| B11 Check-in. Scans are rate limited per staff member (throttle:checkin,
| 120/min). A repeated scan is answered "duplicate", so no idempotency key
| is needed. B12 walk-ins DO need one: a double tap would count twice.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified', 'account:business'])
    ->where(['organization' => $uuid, 'event' => $uuid, 'attendance' => $uuid, 'walkIn' => $uuid])
    ->prefix('organizations/{organization}/events/{event}/checkin')->name('organizations.events.checkin.')
    ->group(function () {
        Route::get('/', [CheckInController::class, 'summary'])->name('summary');
        Route::get('search', [CheckInController::class, 'search'])->name('search');
        Route::post('scan', [CheckInController::class, 'scan'])->middleware('throttle:checkin')->name('scan');
        Route::post('manual', [CheckInController::class, 'manual'])->middleware('throttle:checkin')->name('manual');
        Route::post('{attendance}/undo', [CheckInController::class, 'undo'])->middleware('throttle:sensitive')->name('undo');
    });

Route::middleware(['auth:sanctum', 'active', 'verified', 'account:business'])
    ->where(['organization' => $uuid, 'event' => $uuid, 'walkIn' => $uuid])
    ->prefix('organizations/{organization}/events/{event}/walk-ins')->name('organizations.events.walk-ins.')
    ->group(function () {
        Route::get('/', [WalkInController::class, 'summary'])->name('summary');
        Route::post('sale', [WalkInController::class, 'sale'])->middleware(['throttle:checkin', 'idempotent'])->name('sale');
        Route::post('name', [WalkInController::class, 'name'])->middleware(['throttle:checkin', 'idempotent'])->name('name');
        Route::post('headcount', [WalkInController::class, 'headcount'])->middleware(['throttle:checkin', 'idempotent'])->name('headcount');
        Route::post('{walkIn}/undo', [WalkInController::class, 'undo'])->middleware('throttle:sensitive')->name('undo');
    });
