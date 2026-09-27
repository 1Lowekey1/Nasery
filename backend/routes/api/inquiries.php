<?php

use App\Http\Controllers\Api\V1\Inquiries\ClientInquiryController;
use App\Http\Controllers\Api\V1\Inquiries\OrganizerInquiryController;
use App\Http\Controllers\Api\V1\Inquiries\ThreadController;
use Illuminate\Support\Facades\Route;

/*
| B4 Inquiries. {inquiry} binds under RLS: a client sees only their own
| inquiries; an organizer only inquiries their organization was invited to.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified'])
    ->where(['inquiry' => $uuid, 'organization' => $uuid])
    ->group(function () {

        // Clients
        Route::prefix('inquiries')->name('inquiries.')->middleware('account:client')->group(function () {
            Route::get('/', [ClientInquiryController::class, 'index'])->name('index');
            Route::post('/', [ClientInquiryController::class, 'store'])->middleware('throttle:sensitive')->name('store');
            Route::get('{inquiry}', [ClientInquiryController::class, 'show'])->name('show');
            Route::patch('{inquiry}', [ClientInquiryController::class, 'update'])->middleware('throttle:sensitive')->name('update');
            Route::delete('{inquiry}', [ClientInquiryController::class, 'destroy'])->middleware('throttle:sensitive')->name('destroy');
            Route::get('{inquiry}/matches', [ClientInquiryController::class, 'matches'])->name('matches');
            Route::post('{inquiry}/submit', [ClientInquiryController::class, 'submit'])
                ->middleware(['throttle:sensitive', 'idempotent'])->name('submit');
            Route::post('{inquiry}/invite', [ClientInquiryController::class, 'invite'])
                ->middleware(['throttle:sensitive', 'idempotent'])->name('invite');
            Route::post('{inquiry}/cancel', [ClientInquiryController::class, 'cancel'])->middleware('throttle:sensitive')->name('cancel');
        });

        // Organizers ("Matching inquiries")
        Route::prefix('organizations/{organization}/inquiries')->name('organizations.inquiries.')->middleware('account:business')->group(function () {
            Route::get('/', [OrganizerInquiryController::class, 'index'])->name('index');
            Route::get('{inquiry}', [OrganizerInquiryController::class, 'show'])->name('show');
            Route::post('{inquiry}/decline', [OrganizerInquiryController::class, 'decline'])->middleware('throttle:sensitive')->name('decline');
        });

        // Client–organizer threads (0042). The service checks who is a participant.
        Route::get('inquiries/{inquiryId}/threads', [ThreadController::class, 'forInquiry'])->middleware('account:client')->whereUuid('inquiryId')->name('inquiries.threads');
        Route::get('organizations/{organization}/threads', [ThreadController::class, 'forOrganization'])->middleware('account:business')->name('organizations.threads');
        Route::get('threads/{thread}', [ThreadController::class, 'show'])->whereUuid('thread')->name('threads.show');
        Route::post('threads/{thread}/messages', [ThreadController::class, 'send'])->whereUuid('thread')->middleware('throttle:sensitive')->name('threads.send');
    });
