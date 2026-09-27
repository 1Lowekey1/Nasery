<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-level account category gate: ->middleware('account:business'),
 * ->middleware('account:admin'), ->middleware('account:client,business').
 * Organization-level rights are checked separately (OrganizationAccess).
 */
class EnsureAccountType
{
    public function handle(Request $request, Closure $next, string ...$types): Response
    {
        $type = $request->user()?->account_type?->value;

        if ($type === null || ! in_array($type, $types, true)) {
            throw new ApiException(ErrorCode::AccountTypeNotAllowed, 'Your account type cannot use this feature.', 403);
        }

        return $next($request);
    }
}
