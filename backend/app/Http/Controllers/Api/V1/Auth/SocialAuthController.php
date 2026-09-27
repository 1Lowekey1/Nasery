<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Enums\AccountType;
use App\Enums\SocialProvider;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialRedirectRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\SocialAuthService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SocialAuthController extends Controller
{
    public function __construct(private readonly SocialAuthService $social) {}

    /**
     * Which providers are usable (so the SPA can hide unconfigured buttons).
     */
    public function providers(): JsonResponse
    {
        return ApiResponse::success(collect(SocialProvider::cases())->map(fn (SocialProvider $p) => [
            'provider' => $p->value,
            'label' => $p->label(),
            'enabled' => $p->isConfigured(),
        ])->values());
    }

    /**
     * Step 1 (SPA, fetch): get the provider URL; sets the flow cookie.
     */
    public function redirect(SocialRedirectRequest $request, SocialProvider $provider): JsonResponse
    {
        $start = $this->social->start(
            $provider,
            $request->string('mode'),
            $request->filled('account_type') ? AccountType::from($request->string('account_type')) : null,
            $request,
        );

        $response = ApiResponse::success(['redirect_url' => $start['url']]);

        foreach ($start['cookies'] as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }

    /**
     * Step 2 (browser navigation from the provider; Apple POSTs).
     */
    public function callback(Request $request, SocialProvider $provider): RedirectResponse
    {
        return redirect()->away($this->social->callback($provider, $request));
    }

    /**
     * Step 3 (SPA, first-party + CSRF): one-time code -> session.
     */
    public function exchange(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'size:48']]);

        $user = $this->social->exchange($request->string('code'), $request);

        return ApiResponse::success(new UserResource($user), 'Signed in.')
            ->withCookie($this->social->forgetFlowCookie());
    }

    public function unlink(Request $request, SocialProvider $provider): JsonResponse
    {
        $this->social->unlink($request->user(), $provider);

        return ApiResponse::success(new UserResource($request->user()), "{$provider->label()} disconnected.");
    }
}
