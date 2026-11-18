<?php

use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\Admin\OrganizerApplicationController;
use App\Http\Controllers\Api\V1\Organizations\ApprovalController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationDocumentController;
use App\Http\Controllers\Api\V1\Organizations\EventTypeController;
use App\Http\Controllers\Api\V1\Organizations\EventTypeSuggestionController;
use App\Http\Controllers\Api\V1\Organizations\ServiceSuggestionController;
use App\Http\Controllers\Api\V1\Organizations\OfferedServiceController;
use App\Http\Controllers\Api\V1\Organizations\PriceOptionController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationController;
use App\Http\Controllers\Api\V1\Organizations\OrganizationProfileController;
use Illuminate\Support\Facades\Route;

/*
| B2 Organizer onboarding & approval.
| {organization} is resolved under RLS: a non-member (non-admin) gets 404.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified'])
    ->where(['organization' => $uuid, 'service' => $uuid, 'eventType' => $uuid])
    ->group(function () {

        Route::get('services', [OfferedServiceController::class, 'catalog'])->name('services.catalog');

        Route::middleware('account:business')->group(function () {
            Route::post('organizations', [OrganizationController::class, 'store'])
                ->middleware('throttle:sensitive')->name('organizations.store');
            Route::get('organizations/mine', [OrganizationController::class, 'mine'])->name('organizations.mine');
        });

        Route::prefix('organizations/{organization}')->name('organizations.')->scopeBindings()->group(function () {
            Route::get('/', [OrganizationController::class, 'show'])->name('show');
            Route::patch('/', [OrganizationController::class, 'update'])->middleware('throttle:sensitive')->name('update');

            Route::get('profile', [OrganizationProfileController::class, 'show'])->name('profile.show');
            Route::patch('profile', [OrganizationProfileController::class, 'update'])->middleware('throttle:sensitive')->name('profile.update');
            Route::post('profile/{slot}', [MediaController::class, 'storeOrganizationImage'])->whereIn('slot', ['logo', 'cover'])
                ->middleware('throttle:sensitive')->name('profile.image.store');
            Route::delete('profile/{slot}', [MediaController::class, 'destroyOrganizationImage'])->whereIn('slot', ['logo', 'cover'])
                ->middleware('throttle:sensitive')->name('profile.image.destroy');

            Route::get('services', [OfferedServiceController::class, 'index'])->name('services.index');
            Route::post('services', [OfferedServiceController::class, 'store'])->middleware('throttle:sensitive')->name('services.store');
            Route::patch('services/{service}', [OfferedServiceController::class, 'update'])->middleware('throttle:sensitive')->name('services.update');
            Route::delete('services/{service}', [OfferedServiceController::class, 'destroy'])->middleware('throttle:sensitive')->name('services.destroy');

            Route::get('price-options', [PriceOptionController::class, 'index'])->name('price-options.index');
            Route::post('price-options', [PriceOptionController::class, 'store'])->middleware('throttle:sensitive')->name('price-options.store');
            Route::patch('price-options/{priceOption}', [PriceOptionController::class, 'update'])->middleware('throttle:sensitive')->name('price-options.update');
            Route::delete('price-options/{priceOption}', [PriceOptionController::class, 'destroy'])->middleware('throttle:sensitive')->name('price-options.destroy');
            // 0063: each item's gallery (up to 10 pictures; the first is the cover).
            Route::post('price-options/{priceOption}/images', [PriceOptionController::class, 'storeImage'])->middleware('throttle:sensitive')->name('price-options.images.store');
            Route::delete('price-options/{priceOption}/images/{image}', [PriceOptionController::class, 'destroyImage'])
                ->whereUuid('image')->middleware('throttle:sensitive')->name('price-options.images.destroy');
            Route::post('price-options/{priceOption}/images/{image}/cover', [PriceOptionController::class, 'coverImage'])
                ->whereUuid('image')->middleware('throttle:sensitive')->name('price-options.images.cover');

            Route::get('event-types', [EventTypeController::class, 'index'])->name('event-types.index');
            Route::post('event-types', [EventTypeController::class, 'store'])->middleware('throttle:sensitive')->name('event-types.store');
            Route::patch('event-types/{eventType}', [EventTypeController::class, 'update'])->middleware('throttle:sensitive')->name('event-types.update');
            Route::delete('event-types/{eventType}', [EventTypeController::class, 'destroy'])->middleware('throttle:sensitive')->name('event-types.destroy');
            // 0054: suggest a new event type for NaSeRy's standard list (admins decide).
            Route::get('event-type-suggestions', [EventTypeSuggestionController::class, 'index'])->name('event-type-suggestions.index');
            Route::post('event-type-suggestions', [EventTypeSuggestionController::class, 'store'])->middleware('throttle:sensitive')->name('event-type-suggestions.store');
            // 0056: services not in the catalog: the organization's own right away, and a suggestion for the catalog.
            Route::get('service-suggestions', [ServiceSuggestionController::class, 'index'])->name('service-suggestions.index');
            Route::post('service-suggestions', [ServiceSuggestionController::class, 'store'])->middleware('throttle:sensitive')->name('service-suggestions.store');

            Route::get('approval', [ApprovalController::class, 'show'])->name('approval.show');
            // 0057: proof of business, uploaded here instead of by e-mail.
            Route::get('documents', [OrganizationDocumentController::class, 'index'])->name('documents.index');
            Route::post('documents', [OrganizationDocumentController::class, 'store'])->middleware('throttle:sensitive')->name('documents.store');
            Route::delete('documents/{document}', [OrganizationDocumentController::class, 'destroy'])->whereUuid('document')->middleware('throttle:sensitive')->name('documents.destroy');
            Route::get('documents/{document}/file', [OrganizationDocumentController::class, 'file'])->whereUuid('document')->name('documents.file');
            Route::post('approval/resubmit', [ApprovalController::class, 'resubmit'])->middleware('throttle:sensitive')->name('approval.resubmit');
        });

        Route::prefix('admin')->name('admin.')->middleware('account:admin')->group(function () {
            Route::get('organizer-applications', [OrganizerApplicationController::class, 'index'])->name('applications.index');
            Route::get('organizer-applications/{organization}', [OrganizerApplicationController::class, 'show'])->name('applications.show');
            Route::post('organizer-applications/{organization}/note', [OrganizerApplicationController::class, 'note'])
                ->middleware(['throttle:sensitive', 'idempotent'])->name('applications.note');
            Route::post('organizer-applications/{organization}/decision', [OrganizerApplicationController::class, 'decide'])
                ->middleware(['throttle:sensitive', 'idempotent'])->name('applications.decide');
        });
    });
