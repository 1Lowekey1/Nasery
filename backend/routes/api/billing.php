<?php

use App\Http\Controllers\Api\V1\Billing\BillingController;
use Illuminate\Support\Facades\Route;

/*
| B18 SaaS billing: the organization's plan (members read, owners manage)
| and the admin's plan management.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified'])->where(['organization' => $uuid, 'plan' => $uuid])->group(function () {
    Route::prefix('organizations/{organization}/billing')->name('organizations.billing.')->middleware('account:business')->group(function () {
        Route::get('/', [BillingController::class, 'show'])->name('show');
        Route::get('quote', [BillingController::class, 'quote'])->name('quote');
        Route::post('checkout', [BillingController::class, 'checkout'])->middleware(['throttle:checkout', 'idempotent'])->name('checkout');
        Route::post('cancel', [BillingController::class, 'cancel'])->middleware('throttle:sensitive')->name('cancel');
        Route::post('resume', [BillingController::class, 'resume'])->middleware('throttle:sensitive')->name('resume');
    });

    Route::prefix('admin')->name('admin.')->middleware('account:admin')->group(function () {
        Route::get('billing', [BillingController::class, 'admin'])->name('billing');
        Route::patch('plans/{plan}', [BillingController::class, 'updatePlan'])->middleware('throttle:sensitive')->name('plans.update');
    });
});
