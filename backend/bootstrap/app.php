<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\ApplyDatabaseContext;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureAccountType;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\TrustConfiguredProxies;
use App\Services\Auth\SocialAuthService;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\HandlePrecognitiveRequests;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->replace(TrustProxies::class, TrustConfiguredProxies::class);

        // API only: a signed-out request is a 401 JSON answer, never a redirect to a
        // "login" route (there is none; the Nuxt site has its own sign-in page).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/login');

        // Sanctum SPA (cookie + CSRF) authentication for the Nuxt frontend.
        $middleware->statefulApi();

        $middleware->throttleApi('api');

        // Every API request runs inside the RLS-scoped transaction by default.
        $middleware->appendToGroup('api', ApplyDatabaseContext::class);

        $middleware->alias([
            'idempotent' => EnsureIdempotency::class,
            'active' => EnsureAccountIsActive::class,
            'verified' => EnsureEmailIsVerified::class,
            'account' => EnsureAccountType::class,
        ]);

        // Raw random values that must round-trip unchanged: our social-login
        // flow cookie, and Apple's nonce cookie (already encrypted by the
        // provider package).
        $middleware->encryptCookies(except: [SocialAuthService::FLOW_COOKIE, 'socialite_apple_nonce']);

        // Idempotency must wrap the DB transaction (to record the final,
        // committed outcome), and the DB context must be active before route
        // model binding so bound models are fetched under RLS.
        $middleware->priority([
            HandlePrecognitiveRequests::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            AuthenticatesRequests::class,
            ThrottleRequests::class,
            ThrottleRequestsWithRedis::class,
            AuthenticatesSessions::class,
            EnsureIdempotency::class,
            ApplyDatabaseContext::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(
            fn (Throwable $e, Request $request) => $request->is('api/*')
                ? ApiExceptionRenderer::render($e, $request)
                : null,
        );

        $exceptions->dontReportWhen(fn (Throwable $e) => $e instanceof \App\Exceptions\ApiException && $e->status < 500);

        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'token']);
    })->create();
