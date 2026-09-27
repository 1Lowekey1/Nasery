<?php

use App\Http\Controllers\Api\V1\Quotations\ClientQuotationController;
use App\Http\Controllers\Api\V1\Quotations\OrganizerQuotationController;
use Illuminate\Support\Facades\Route;

/*
| B5 Quotations. {quotation} binds under RLS: organizers see their own
| organizations' quotations; clients only non-draft quotations on their own
| inquiries (0032).
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified'])
    ->where(['inquiry' => $uuid, 'organization' => $uuid, 'quotation' => $uuid])
    ->group(function () {

        // Clients: compare, accept, decline
        Route::middleware('account:client')->group(function () {
            Route::get('inquiries/{inquiry}/quotations', [ClientQuotationController::class, 'index'])->name('inquiries.quotations.index');
            Route::post('quotations/{quotation}/accept', [ClientQuotationController::class, 'accept'])
                ->middleware(['throttle:sensitive', 'idempotent'])->name('quotations.accept');
            Route::post('quotations/{quotation}/decline', [ClientQuotationController::class, 'decline'])
                ->middleware('throttle:sensitive')->name('quotations.decline');
        });

        // Organizers ("My Offers")
        Route::prefix('organizations/{organization}')->name('organizations.')->middleware('account:business')->group(function () {
            Route::post('inquiries/{inquiry}/quotations', [OrganizerQuotationController::class, 'store'])
                ->middleware(['throttle:sensitive', 'idempotent'])->name('inquiries.quotations.store');

            Route::prefix('quotations')->name('quotations.')->group(function () {
                Route::get('/', [OrganizerQuotationController::class, 'index'])->name('index');
                Route::get('{quotation}', [OrganizerQuotationController::class, 'show'])->name('show');
                Route::patch('{quotation}', [OrganizerQuotationController::class, 'update'])->middleware('throttle:sensitive')->name('update');
                Route::delete('{quotation}', [OrganizerQuotationController::class, 'destroy'])->middleware('throttle:sensitive')->name('destroy');
                Route::post('{quotation}/submit', [OrganizerQuotationController::class, 'submit'])
                    ->middleware(['throttle:sensitive', 'idempotent'])->name('submit');
                Route::post('{quotation}/revise', [OrganizerQuotationController::class, 'revise'])
                    ->middleware(['throttle:sensitive', 'idempotent'])->name('revise');
                Route::post('{quotation}/withdraw', [OrganizerQuotationController::class, 'withdraw'])
                    ->middleware('throttle:sensitive')->name('withdraw');
            });
        });
    });
