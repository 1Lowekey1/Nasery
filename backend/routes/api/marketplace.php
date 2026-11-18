<?php

use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\Marketplace\MarketplaceController;
use Illuminate\Support\Facades\Route;

/*
| B3 Marketplace — public (guests and signed-in users), read-only.
| Reads only the controlled public views.
*/

Route::prefix('marketplace')->name('marketplace.')
    ->withoutMiddleware('throttle:api')->middleware('throttle:public')
    ->group(function () {
        Route::get('organizers', [MarketplaceController::class, 'organizers'])->name('organizers');
        Route::get('organizers/{slug}', [MarketplaceController::class, 'organizer'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('organizer');
        Route::get('organizers/{slug}/reviews', [MarketplaceController::class, 'reviews'])
            ->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('organizer.reviews');
        Route::get('event-types', [MarketplaceController::class, 'eventTypes'])->name('event-types');
        Route::get('services', [MarketplaceController::class, 'services'])->name('services');
    });

// Uploaded pictures (profile photos, logos, covers): public by link, served
// with a fixed image type; the path must be a known folder + UUID.webp.
Route::get('media/{folder}/{file}', [MediaController::class, 'show'])
    // Every picture folder ImageStore writes to (0062 added price-options and mood-boards).
    ->whereIn('folder', array_column(\App\Services\Media\ImageStore::KINDS, 0))
    ->where('file', '[0-9a-f-]{36}\.webp')
    ->withoutMiddleware('throttle:api')->middleware('throttle:public')
    ->name('media.show');
