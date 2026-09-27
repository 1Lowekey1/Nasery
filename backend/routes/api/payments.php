<?php

use App\Http\Controllers\Api\V1\Payments\OrganizerPaymentController;
use App\Http\Controllers\Api\V1\Payments\PaymentController;
use Illuminate\Support\Facades\Route;

/*
| B10 Payments. The webhook is called by PayMongo's servers (no session, no
| CSRF: not a stateful SPA request) and authenticated by its signature.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::post('payments/paymongo/webhook', [PaymentController::class, 'webhook'])
    ->middleware('throttle:webhook')->name('payments.webhook');

// Demo gateway (PAYMONGO_DRIVER=fake): signed, expiring pay/fail link.
Route::post('payments/demo/{payment}/complete', [PaymentController::class, 'demoComplete'])
    ->where('payment', $uuid)->middleware(['signed', 'throttle:sensitive'])->name('payments.fake.complete');

Route::middleware(['auth:sanctum', 'active', 'verified'])
    ->where(['registration' => $uuid, 'payment' => $uuid, 'organization' => $uuid, 'event' => $uuid])
    ->group(function () {
        Route::get('me/registrations/pending', [PaymentController::class, 'pending'])->name('me.registrations.pending');
        Route::post('me/registrations/{registration}/checkout', [PaymentController::class, 'checkout'])
            ->middleware(['throttle:checkout', 'idempotent'])->name('me.registrations.checkout');
        Route::post('me/registrations/{registration}/cancel', [PaymentController::class, 'cancel'])->middleware('throttle:sensitive')->name('me.registrations.cancel');
        Route::get('me/payments/{payment}', [PaymentController::class, 'show'])->name('me.payments.show');

        Route::prefix('organizations/{organization}/events/{event}')->name('organizations.events.')->middleware('account:business')->group(function () {
            Route::get('payments', [OrganizerPaymentController::class, 'index'])->name('payments.index');
            Route::post('payments/{payment}/refund', [OrganizerPaymentController::class, 'refund'])
                ->middleware(['throttle:sensitive', 'idempotent'])->name('payments.refund');
        });
    });
