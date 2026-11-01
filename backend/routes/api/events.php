<?php

use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\Events\ClientEventController;
use App\Http\Controllers\Api\V1\Events\EventActivityController;
use App\Http\Controllers\Api\V1\Events\OrganizerEventController;
use App\Http\Controllers\Api\V1\Events\PreparationController;
use Illuminate\Support\Facades\Route;

/*
| B6 Events. {event} binds under RLS: organization members see their
| organization's events; clients only events made from their inquiries.
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active', 'verified'])
    ->where(['event' => $uuid, 'organization' => $uuid, 'quotation' => $uuid, 'session' => $uuid, 'checklist' => $uuid, 'task' => $uuid, 'activity' => $uuid])
    ->group(function () {

        // Clients
        Route::prefix('events')->name('events.')->middleware('account:client')->group(function () {
            Route::get('/', [ClientEventController::class, 'index'])->name('index');
            Route::get('{event}', [ClientEventController::class, 'show'])->name('show');
            Route::get('{event}/progress', [ClientEventController::class, 'progress'])->name('progress');
            Route::get('{event}/attendance', [ClientEventController::class, 'attendance'])->name('attendance');
            Route::get('{event}/report', [ClientEventController::class, 'report'])->name('report');
            Route::get('{event}/report/pdf', [ClientEventController::class, 'reportPdf'])->name('report.pdf');
        });

        // Organizers
        Route::prefix('organizations/{organization}')->name('organizations.')->middleware('account:business')->group(function () {
            Route::post('quotations/{quotation}/event', [OrganizerEventController::class, 'convert'])
                ->middleware(['throttle:sensitive', 'idempotent'])->name('quotations.event');

            Route::prefix('events')->name('events.')->scopeBindings()->group(function () {
                Route::get('/', [OrganizerEventController::class, 'index'])->name('index');
                Route::post('/', [OrganizerEventController::class, 'store'])->middleware(['throttle:sensitive', 'idempotent'])->name('store');
                Route::get('{event}', [OrganizerEventController::class, 'view'])->name('show');
                Route::patch('{event}', [OrganizerEventController::class, 'update'])->middleware('throttle:sensitive')->name('update');
                Route::delete('{event}', [OrganizerEventController::class, 'destroy'])->middleware('throttle:sensitive')->name('destroy');
                Route::put('{event}/agreed-amount', [OrganizerEventController::class, 'agreedAmount'])->middleware('throttle:sensitive')->name('agreed-amount');
                Route::post('{event}/cover', [MediaController::class, 'storeEventCover'])->middleware('throttle:sensitive')->name('cover.store');
                Route::delete('{event}/cover', [MediaController::class, 'destroyEventCover'])->middleware('throttle:sensitive')->name('cover.destroy');
                Route::post('{event}/{action}', [OrganizerEventController::class, 'transition'])
                    ->whereIn('action', ['publish', 'unpublish', 'start', 'stop', 'complete', 'cancel'])
                    ->middleware('throttle:sensitive')->name('transition');

                Route::post('{event}/sessions', [OrganizerEventController::class, 'storeSession'])->middleware('throttle:sensitive')->name('sessions.store');
                Route::patch('{event}/sessions/{session}', [OrganizerEventController::class, 'updateSession'])->middleware('throttle:sensitive')->name('sessions.update');
                Route::delete('{event}/sessions/{session}', [OrganizerEventController::class, 'destroySession'])->middleware('throttle:sensitive')->name('sessions.destroy');

                // B7 preparation + timeline
                Route::get('{event}/preparation', [PreparationController::class, 'show'])->name('preparation');
                Route::get('{event}/timeline', [PreparationController::class, 'timeline'])->name('timeline');
                Route::put('{event}/readiness', [PreparationController::class, 'overrideReadiness'])->middleware('throttle:sensitive')->name('readiness');
                Route::post('{event}/checklists', [PreparationController::class, 'storeChecklist'])->middleware('throttle:sensitive')->name('checklists.store');
                Route::patch('{event}/checklists/{checklist}', [PreparationController::class, 'updateChecklist'])->middleware('throttle:sensitive')->name('checklists.update');
                Route::delete('{event}/checklists/{checklist}', [PreparationController::class, 'destroyChecklist'])->middleware('throttle:sensitive')->name('checklists.destroy');
                Route::post('{event}/checklists/{checklist}/tasks', [PreparationController::class, 'storeTask'])->middleware('throttle:sensitive')->name('tasks.store');
                Route::patch('{event}/tasks/{task}', [PreparationController::class, 'updateTask'])->middleware('throttle:sensitive')->name('tasks.update');
                Route::delete('{event}/tasks/{task}', [PreparationController::class, 'destroyTask'])->middleware('throttle:sensitive')->name('tasks.destroy');
                Route::post('{event}/tasks/{task}/review', [PreparationController::class, 'reviewTask'])->middleware('throttle:sensitive')->name('tasks.review');

                // 0045 Activity Timeline: the written event program
                Route::post('{event}/activities', [EventActivityController::class, 'store'])->middleware('throttle:sensitive')->name('activities.store');
                Route::patch('{event}/activities/{activity}', [EventActivityController::class, 'update'])->middleware('throttle:sensitive')->name('activities.update');
                Route::delete('{event}/activities/{activity}', [EventActivityController::class, 'destroy'])->middleware('throttle:sensitive')->name('activities.destroy');
            });

            // Every visible event's activities + automatic history
            Route::get('activities', [EventActivityController::class, 'index'])->name('activities.index');
        });
    });
