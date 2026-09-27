<?php

use App\Http\Controllers\Api\V1\Reports\ReportController;
use Illuminate\Support\Facades\Route;

/*
| B14 post-event attendance report for organizers (clients read shared
| versions through routes/api/events.php).
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified', 'account:business'])
    ->where(['organization' => $uuid, 'event' => $uuid])
    ->prefix('organizations/{organization}/events/{event}/report')->name('organizations.events.report.')
    ->group(function () {
        Route::get('/', [ReportController::class, 'show'])->name('show');
        Route::get('pdf', [ReportController::class, 'pdf'])->name('pdf');
        Route::get('csv', [ReportController::class, 'csv'])->name('csv');
        Route::post('share', [ReportController::class, 'share'])->middleware(['throttle:sensitive', 'idempotent'])->name('share');
    });
