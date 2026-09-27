<?php

use App\Http\Controllers\Api\V1\Reviews\ReviewController;
use Illuminate\Support\Facades\Route;

/*
| B16 reviews. Clients review their own completed events (route model
| binding under RLS limits {event} to the client's events); organizers read
| theirs and owners reply; anyone signed in can report a review.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified'])->where(['event' => $uuid, 'organization' => $uuid, 'review' => $uuid])->group(function () {
    Route::prefix('events/{event}/review')->name('events.review.')->middleware('account:client')->group(function () {
        Route::get('/', [ReviewController::class, 'mine'])->name('show');
        Route::post('/', [ReviewController::class, 'store'])->middleware(['throttle:sensitive', 'idempotent'])->name('store');
        Route::patch('/', [ReviewController::class, 'update'])->middleware('throttle:sensitive')->name('update');
        Route::delete('/', [ReviewController::class, 'destroy'])->middleware('throttle:sensitive')->name('destroy');
    });

    Route::prefix('organizations/{organization}/reviews')->name('organizations.reviews.')->middleware('account:business')->group(function () {
        Route::get('/', [ReviewController::class, 'index'])->name('index');
        Route::put('{review}/reply', [ReviewController::class, 'reply'])->middleware('throttle:sensitive')->name('reply');
        Route::delete('{review}/reply', [ReviewController::class, 'removeReply'])->middleware('throttle:sensitive')->name('reply.destroy');
    });

    Route::post('reviews/{review}/report', [ReviewController::class, 'report'])->middleware('throttle:sensitive')->name('reviews.report');
});
