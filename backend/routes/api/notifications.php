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
    Route::get('notification-preferences', [NotificationController::class, 'preferences'])->name('notification-preferences.show');
    Route::put('notification-preferences', [NotificationController::class, 'updatePreferences'])->middleware('throttle:sensitive')->name('notification-preferences.update');
});
