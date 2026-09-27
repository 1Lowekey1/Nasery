<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\AccountService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suspended / deactivated / deleted accounts lose access immediately, even
 * with a live session: the session is ended and the request refused.
 * Applied to every authenticated route (alias: `active`).
 */
class EnsureAccountIsActive
{
    public function __construct(private readonly AccountService $accounts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $this->accounts->assertActive($user); // throws the matching 403
        }

        return $next($request);
    }
}
