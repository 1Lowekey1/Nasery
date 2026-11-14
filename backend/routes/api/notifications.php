<?php

use App\Http\Controllers\Api\V1\Notifications\NotificationController;
use Illuminate\Support\Facades\Route;

/*
| B13 notifications for the signed-in user (RLS: own rows only).
*/

$uuid = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

Route::middleware(['auth:sanctum', 'active'])->where(['notification' => $uuid])->prefix('me')->name('me.')->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->name('notifications.unread');
    Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');
    // Mock defense: clearing notifications (hidden from the list, kept in the database).
    Route::post('notifications/clear', [NotificationController::class, 'clear'])->name('notifications.clear');
    // Live updates (option B): pages reload only when this changes.
    Route::get('changes', [\App\Http\Controllers\Api\V1\Notifications\ChangeStampController::class, 'show'])->name('changes');
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->name('notifications.destroy');
    Route::get('notification-preferences', [NotificationController::class, 'preferences'])->name('notification-preferences.show');
    Route::put('notification-preferences', [NotificationController::class, 'updatePreferences'])->middleware('throttle:sensitive')->name('notification-preferences.update');
});
