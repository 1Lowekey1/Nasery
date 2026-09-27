<?php

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| NaSeRy API v1  (prefix: /api/v1)
|--------------------------------------------------------------------------
|
| Every route here already runs through: request id -> Sanctum stateful
| (session + CSRF) -> throttle:api -> RLS-scoped DB transaction.
| Module route files (auth, organizer, marketplace, ...) are added per phase.
|
*/

Route::get('/health', HealthController::class)
    ->withoutMiddleware('throttle:api')
    ->middleware('throttle:public')
    ->name('api.v1.health');

Route::name('api.v1.')->group(function () {
    require __DIR__.'/api/auth.php';
    require __DIR__.'/api/organizations.php';
    require __DIR__.'/api/marketplace.php';
    require __DIR__.'/api/inquiries.php';
    require __DIR__.'/api/quotations.php';
    require __DIR__.'/api/events.php';
    require __DIR__.'/api/staff.php';
    require __DIR__.'/api/ticketing.php';
    require __DIR__.'/api/payments.php';
    require __DIR__.'/api/checkin.php';
    require __DIR__.'/api/notifications.php';
    require __DIR__.'/api/reports.php';
    require __DIR__.'/api/analytics.php';
    require __DIR__.'/api/reviews.php';
    require __DIR__.'/api/admin.php';
    require __DIR__.'/api/billing.php';
});
