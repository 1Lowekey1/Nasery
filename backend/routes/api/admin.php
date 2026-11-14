<?php

use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Organizations\EventTypeSuggestionController;
use App\Http\Controllers\Api\V1\Organizations\ServiceSuggestionController;
use App\Http\Controllers\Api\V1\Organizations\NaseryAccessController;
use App\Http\Controllers\Api\V1\Payments\EarningsController;
use Illuminate\Support\Facades\Route;

/*
| B17 platform administration, the organization's side of admin access,
| and the public announcements feed. (Organizer applications stay in
| organizations.php from B2.)
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';
$ids = ['organization' => $uuid, 'accessRequest' => $uuid, 'grant' => $uuid, 'review' => $uuid, 'refund' => $uuid,
    'user' => $uuid, 'service' => $uuid, 'announcement' => $uuid, 'eventType' => $uuid, 'payout' => $uuid];

Route::get('announcements', [NaseryAccessController::class, 'announcements'])->middleware('throttle:public')->name('announcements');

Route::middleware(['auth:sanctum', 'active', 'verified'])->where($ids)->group(function () {
    // Organization owners and members: NaSeRy access to their workspace.
    Route::prefix('organizations/{organization}/nasery-access')->name('organizations.nasery-access.')->middleware('account:business')->group(function () {
        Route::get('/', [NaseryAccessController::class, 'index'])->name('index');
        Route::post('invite', [NaseryAccessController::class, 'invite'])->middleware('throttle:sensitive')->name('invite');
        Route::delete('invite/{accessRequest}', [NaseryAccessController::class, 'cancelInvite'])->middleware('throttle:sensitive')->name('invite.cancel');
        Route::post('requests/{accessRequest}/decision', [NaseryAccessController::class, 'decide'])->middleware('throttle:sensitive')->name('decide');
        Route::delete('grants/{grant}', [NaseryAccessController::class, 'endGrant'])->middleware('throttle:sensitive')->name('grants.end');
    });

    Route::prefix('admin')->name('admin.')->middleware('account:admin')->group(function () {
        Route::get('overview', [AdminController::class, 'overview'])->name('overview');
        Route::get('analytics', [AdminController::class, 'analytics'])->name('analytics');
        Route::get('earnings', [AdminController::class, 'earnings'])->name('earnings');
        Route::get('events', [AdminController::class, 'events'])->name('events');

        Route::get('access', [AdminController::class, 'access'])->name('access');
        Route::post('organizations/{organization}/access-requests', [AdminController::class, 'requestAccess'])->middleware('throttle:sensitive')->name('access.request');
        Route::delete('access-requests/{accessRequest}', [AdminController::class, 'cancelRequest'])->middleware('throttle:sensitive')->name('access.cancel');
        Route::post('access-requests/{accessRequest}/accept', [AdminController::class, 'acceptInvite'])->middleware('throttle:sensitive')->name('access.accept');
        Route::delete('access-grants/{grant}', [AdminController::class, 'endGrant'])->middleware('throttle:sensitive')->name('access.end');
        Route::get('organizations/{organization}/workspace', [AdminController::class, 'workspace'])->name('workspace');

        Route::get('moderation/reviews', [AdminController::class, 'reportedReviews'])->name('moderation.reviews');
        Route::post('moderation/reviews/{review}/start', [AdminController::class, 'startReview'])->middleware('throttle:sensitive')->name('moderation.reviews.start');
        Route::post('moderation/reviews/{review}/decision', [AdminController::class, 'decideReview'])->middleware('throttle:sensitive')->name('moderation.reviews.decide');
        Route::post('moderation/reviews/{review}/restore', [AdminController::class, 'restoreReview'])->middleware('throttle:sensitive')->name('moderation.reviews.restore');
        Route::get('moderation/fee-claims', [AdminController::class, 'feeClaims'])->name('moderation.fee-claims');
        Route::post('moderation/fee-claims/{refund}', [AdminController::class, 'reviewFeeClaim'])->middleware('throttle:sensitive')->name('moderation.fee-claims.review');

        Route::get('settings', [AdminController::class, 'settings'])->name('settings');
        Route::patch('settings', [AdminController::class, 'updateSettings'])->middleware('throttle:sensitive')->name('settings.update');
        Route::post('settings/fee', [AdminController::class, 'changeFee'])->middleware('throttle:sensitive')->name('settings.fee');

        Route::get('users', [AdminController::class, 'users'])->name('users');
        Route::post('users/{user}/status', [AdminController::class, 'userStatus'])->middleware('throttle:sensitive')->name('users.status');

        Route::get('users/{user}', [AdminController::class, 'user'])->name('users.show');
        // Organizer payouts (0043)
        Route::get('payouts', [EarningsController::class, 'balances'])->name('payouts');
        Route::get('organizations/{organization}/payouts', [EarningsController::class, 'payouts'])->name('payouts.organization');
        Route::post('organizations/{organization}/payouts', [EarningsController::class, 'record'])->middleware(['throttle:sensitive', 'idempotent'])->name('payouts.store');
        Route::post('payouts/{payout}/void', [EarningsController::class, 'void'])->middleware('throttle:sensitive')->name('payouts.void');
        Route::get('event-types', [AdminController::class, 'eventTypes'])->name('event-types');
        Route::post('event-types', [AdminController::class, 'saveEventType'])->middleware('throttle:sensitive')->name('event-types.store');
        Route::patch('event-types/{eventType}', [AdminController::class, 'saveEventType'])->middleware('throttle:sensitive')->name('event-types.update');
        Route::delete('event-types/{eventType}', [AdminController::class, 'removeEventType'])->middleware('throttle:sensitive')->name('event-types.destroy');
        // 0054: organizers' event type suggestions (one row per name): approve, merge or reject.
        Route::get('event-type-suggestions', [EventTypeSuggestionController::class, 'pending'])->name('event-type-suggestions');
        Route::post('event-type-suggestions/{suggestion}/{decision}', [EventTypeSuggestionController::class, 'decide'])
            ->whereUuid('suggestion')->whereIn('decision', ['approve', 'merge', 'reject'])->middleware('throttle:sensitive')->name('event-type-suggestions.decide');
        // 0056: organizers' service suggestions, the same way.
        Route::get('service-suggestions', [ServiceSuggestionController::class, 'pending'])->name('service-suggestions');
        Route::post('service-suggestions/{suggestion}/{decision}', [ServiceSuggestionController::class, 'decide'])
            ->whereUuid('suggestion')->whereIn('decision', ['approve', 'merge', 'reject'])->middleware('throttle:sensitive')->name('service-suggestions.decide');
        Route::get('services', [AdminController::class, 'services'])->name('services');
        Route::post('services', [AdminController::class, 'saveService'])->middleware('throttle:sensitive')->name('services.store');
        Route::patch('services/{service}', [AdminController::class, 'saveService'])->middleware('throttle:sensitive')->name('services.update');
        Route::delete('services/{service}', [AdminController::class, 'archiveService'])->middleware('throttle:sensitive')->name('services.archive');
        Route::post('services/{service}/restore', [AdminController::class, 'archiveService'])->middleware('throttle:sensitive')->name('services.restore');

        Route::get('announcements', [AdminController::class, 'announcements'])->name('announcements');
        Route::post('announcements', [AdminController::class, 'saveAnnouncement'])->middleware('throttle:sensitive')->name('announcements.store');
        Route::patch('announcements/{announcement}', [AdminController::class, 'saveAnnouncement'])->middleware('throttle:sensitive')->name('announcements.update');
        Route::delete('announcements/{announcement}', [AdminController::class, 'deleteAnnouncement'])->middleware('throttle:sensitive')->name('announcements.destroy');

        Route::get('activity', [AdminController::class, 'activity'])->name('activity');

        Route::get('admins', [AdminController::class, 'admins'])->name('admins');
        Route::post('admins', [AdminController::class, 'inviteAdmin'])->middleware('throttle:sensitive')->name('admins.invite');
        Route::post('admins/{user}/resend', [AdminController::class, 'resendAdminInvite'])->middleware('throttle:sensitive')->name('admins.resend');
        Route::post('admins/{user}/status', [AdminController::class, 'adminStatus'])->middleware('throttle:sensitive')->name('admins.status');
    });
});
