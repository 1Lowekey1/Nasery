<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for modules that need a confirmed email (inquiries, events, ...).
 * Alias: `verified`. Unverified users can still sign in, see their account
 * and verify.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->email_verified_at === null) {
            throw new ApiException(ErrorCode::EmailNotVerified, 'Verify your email address to continue.', 403);
        }

        return $next($request);
    }
}
