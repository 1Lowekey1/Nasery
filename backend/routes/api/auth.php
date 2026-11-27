<?php

use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SessionController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use Illuminate\Support\Facades\Route;

/*
| B1 Authentication  (/api/v1/auth/...)
| Sanctum SPA cookie sessions. Mutating first-party requests need the
| XSRF token (GET /sanctum/csrf-cookie first).
*/

Route::prefix('auth')->name('auth.')->group(function () {

    // --- Guests -------------------------------------------------------------
    Route::post('register', [AuthController::class, 'register'])
        ->middleware('throttle:register')->name('register');

    Route::post('login', [AuthController::class, 'login'])
        ->middleware(['throttle:login', 'throttle:login-account'])->name('login');

    Route::post('password/forgot', [PasswordController::class, 'forgot'])
        ->middleware('throttle:password-reset')->name('password.forgot');

    Route::post('password/reset', [PasswordController::class, 'reset'])
        ->middleware(['throttle:password-reset', 'throttle:sensitive'])->name('password.reset');

    // Team invitation link (account created by the invitation): set the first password and join.
    Route::post('team-setup', [PasswordController::class, 'teamSetup'])
        ->middleware(['throttle:password-reset', 'throttle:sensitive'])->name('team-setup');
    // Invite fix (Nov 2026): the set-up page checks its link first (doesn't use it).
    Route::post('team-setup/check', [PasswordController::class, 'teamSetupCheck'])
        ->middleware(['throttle:password-reset'])->name('team-setup.check');

    Route::prefix('social')->name('social.')->group(function () {
        Route::get('providers', [SocialAuthController::class, 'providers'])
            ->middleware('throttle:public')->name('providers');

        Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect'])
            ->middleware('throttle:login')->name('redirect');

        // Apple uses response_mode=form_post (POST), Google/Facebook GET.
        Route::match(['get', 'post'], '{provider}/callback', [SocialAuthController::class, 'callback'])
            ->middleware('throttle:login')->name('callback');

        Route::post('exchange', [SocialAuthController::class, 'exchange'])
            ->middleware('throttle:login')->name('exchange');
    });

    // --- Signed in ------------------------------------------------------------
    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');
        Route::get('me', [AuthController::class, 'me'])->name('me');
        Route::patch('me', [AuthController::class, 'updateMe'])->middleware('throttle:sensitive')->name('me.update');
        Route::post('me/avatar', [MediaController::class, 'storeAvatar'])->middleware('throttle:sensitive')->name('me.avatar.store');
        Route::delete('me/avatar', [MediaController::class, 'destroyAvatar'])->middleware('throttle:sensitive')->name('me.avatar.destroy');

        Route::post('email/verification-code', [EmailVerificationController::class, 'send'])
            ->middleware('throttle:email-verification')->name('email.send');
        Route::post('email/verify', [EmailVerificationController::class, 'verify'])
            ->middleware('throttle:sensitive')->name('email.verify');

        Route::put('password', [PasswordController::class, 'change'])
            ->middleware('throttle:sensitive')->name('password.change');

        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::delete('sessions', [SessionController::class, 'destroyOthers'])
            ->middleware('throttle:sensitive')->name('sessions.destroy-others');
        Route::delete('sessions/{session}', [SessionController::class, 'destroy'])
            ->middleware('throttle:sensitive')->where('session', '[a-f0-9]{64}')->name('sessions.destroy');

        Route::delete('social/{provider}', [SocialAuthController::class, 'unlink'])
            ->middleware('throttle:sensitive')->name('social.unlink');
    });
});
