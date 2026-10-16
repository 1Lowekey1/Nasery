<?php

namespace App\Services\Auth;

use App\Enums\AccountType;
use App\Enums\SocialProvider;
use App\Exceptions\ApiException;
use App\Models\Auth\SocialAccount;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as ProviderUser;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Cookie;
use Throwable;

/**
 * Google / Facebook / Apple sign-in, improved over the prototype:
 *
 * - Browser-bound flow (RFC 9700 §2.1): /redirect sets an HttpOnly flow
 *   cookie; the callback and the final exchange only succeed in that same
 *   browser, so a captured callback/exchange URL can't log a victim into the
 *   attacker's account (login CSRF).
 * - Existing accounts are linked only through a provider-VERIFIED email.
 *   Linking to a local account whose email was never verified wipes its
 *   password and sessions (pre-registration hijack defence).
 * - Errors reach the SPA as fixed codes, never exception text.
 * - Ends in a normal Sanctum cookie session via a one-time, short-lived code.
 */
class SocialAuthService
{
    public const FLOW_COOKIE = 'nasery_social_flow';

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AccountService $accounts,
        private readonly SessionService $sessions,
    ) {}

    /**
     * @return array{url: string, cookies: list<Cookie>}
     */
    public function start(SocialProvider $provider, string $mode, ?AccountType $accountType, Request $request): array
    {
        if (! $provider->isConfigured()) {
            throw new ApiException(ErrorCode::SocialProviderNotConfigured, "{$provider->label()} sign-in is not available yet.", 503);
        }

        $state = Str::random(40);
        $flow = Str::random(40);
        $ttl = (int) config('nasery.auth.social.state_ttl_minutes');

        Cache::put("social:state:{$state}", [
            'provider' => $provider->value,
            'mode' => $mode,
            'account_type' => $accountType?->value,
            'flow' => hash('sha256', $flow),
        ], now()->addMinutes($ttl));

        $redirect = $this->driver($provider)->with(['state' => $state])->redirect();

        return [
            'url' => $redirect->getTargetUrl(),
            // Apple's nonce cookie (if any) plus our flow-binding cookie.
            'cookies' => [...$redirect->headers->getCookies(), $this->flowCookie($flow, $ttl, $request)],
        ];
    }

    /**
     * Handle the provider callback. Returns the SPA URL to redirect to:
     * success carries a one-time exchange code, failure an error code.
     */
    public function callback(SocialProvider $provider, Request $request): string
    {
        $state = (string) $request->input('state');
        $attempt = $state !== '' ? Cache::pull("social:state:{$state}") : null;
        $mode = $attempt['mode'] ?? 'login';

        try {
            if ($request->filled('error')) {
                throw new ApiException(ErrorCode::SocialLoginCancelled, 'Sign-in was cancelled.', 400);
            }

            if ($attempt === null || $attempt['provider'] !== $provider->value || ! $this->sameBrowser($request, $attempt['flow'])) {
                throw new ApiException(ErrorCode::SocialLoginExpired, 'The sign-in request expired. Please try again.', 400);
            }

            $profile = $this->driver($provider)->user();
            $user = $this->resolveUser($provider, $profile, $mode, $attempt['account_type']);
            $this->accounts->assertActive($user);

            $code = Str::random(48);
            Cache::put('social:exchange:'.hash('sha256', $code), [
                'user_id' => $user->id,
                'flow' => $attempt['flow'],
            ], now()->addSeconds((int) config('nasery.auth.social.exchange_ttl_seconds')));

            return $this->frontend('/auth/social/callback', ['code' => $code]);
        } catch (Throwable $e) {
            $code = $e instanceof ApiException ? $e->errorCode : ErrorCode::SocialLoginFailed;

            if (! $e instanceof ApiException) {
                report($e);
            }

            Log::channel('security')->notice('auth.social_failed', ['provider' => $provider->value, 'code' => $code->value]);

            return $this->frontend($mode === 'register' ? '/register' : '/login', ['social_error' => $code->value]);
        }
    }

    /**
     * Final step, called by the SPA (first-party, CSRF-protected): turn the
     * one-time code into a session, in the browser that started the flow.
     */
    public function exchange(#[SensitiveParameter] string $code, Request $request): User
    {
        $payload = Cache::pull('social:exchange:'.hash('sha256', $code));

        if ($payload === null || ! $this->sameBrowser($request, $payload['flow'])) {
            throw new ApiException(ErrorCode::SocialLoginExpired, 'This sign-in has expired. Please try again.', 422);
        }

        $user = $this->db->system('auth.social_exchange', fn () => User::query()->find($payload['user_id']));

        if ($user === null) {
            throw new ApiException(ErrorCode::SocialLoginExpired, 'This sign-in has expired. Please try again.', 422);
        }

        $this->accounts->assertActive($user);
        $this->accounts->startSession($user, $request);
        Log::channel('security')->info('auth.login', ['user_id' => $user->id, 'ip' => $request->ip(), 'via' => 'social']);

        return $user;
    }

    public function unlink(User $user, SocialProvider $provider): void
    {
        $linked = SocialAccount::query()->where('user_id', $user->id)->get();

        if (! $linked->contains('provider', $provider)) {
            throw new ApiException(ErrorCode::NotFound, "No {$provider->label()} account is linked.", 404);
        }

        if ($user->password_hash === null && $linked->count() === 1) {
            throw new ApiException(
                ErrorCode::SocialUnlinkBlocked,
                'Set a password before removing your only sign-in method.',
                409,
            );
        }

        SocialAccount::query()->where('user_id', $user->id)->where('provider', $provider)->delete();
        $this->audit->record('user.social_unlinked', $user, old: ['provider' => $provider->value]);
    }

    /**
     * @return list<string>
     */
    public function linkedProviders(User $user): array
    {
        return SocialAccount::query()->where('user_id', $user->id)->orderBy('provider')
            ->pluck('provider')->map(fn (SocialProvider $p) => $p->value)->values()->all();
    }

    private function resolveUser(SocialProvider $provider, ProviderUser $profile, string $mode, ?string $accountType): User
    {
        $providerId = (string) $profile->getId();
        $email = $profile->getEmail() ? AccountService::normalizeEmail($profile->getEmail()) : null;
        $emailVerified = $email !== null && $this->emailVerified($provider, $profile);

        return $this->db->system('auth.social_resolve', function () use ($provider, $profile, $providerId, $email, $emailVerified, $mode, $accountType) {
            $link = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', $providerId)->first();
            $user = $link ? User::query()->find($link->user_id) : null;

            if ($user === null && $email !== null) {
                $existing = $this->accounts->findByEmail($email);

                if ($existing !== null) {
                    if (! $emailVerified) {
                        throw new ApiException(ErrorCode::SocialEmailUnverified, 'Verify your email with the provider first.', 403);
                    }

                    $user = $this->linkExisting($existing, $provider);
                }
            }

            if ($user === null) {
                if ($mode !== 'register' || $accountType === null) {
                    throw new ApiException(ErrorCode::SocialAccountNotRegistered, 'No NaSeRy account uses this sign-in yet.', 404);
                }

                if ($email === null) {
                    throw new ApiException(ErrorCode::SocialLoginFailed, 'The provider did not share an email address.', 422);
                }

                $user = $this->createUser($provider, $profile, $email, $emailVerified, AccountType::from($accountType));
            }

            SocialAccount::query()->updateOrCreate(
                ['provider' => $provider, 'provider_user_id' => $providerId],
                ['user_id' => $user->id, 'provider_email' => $email, 'last_used_at' => now()],
            );

            return $user;
        });
    }

    private function linkExisting(User $user, SocialProvider $provider): User
    {
        if ($user->email_verified_at === null) {
            // Someone may have pre-registered this address with a password.
            // The provider just proved who owns the mailbox: drop that
            // password and any sessions it created.
            $user->forceFill(['email_verified_at' => now(), 'password_hash' => null])->save();
            $this->sessions->revokeAll($user);
        }

        $this->audit->record('user.social_linked', $user, new: ['provider' => $provider->value], actorUserId: $user->id);

        return $user;
    }

    private function createUser(SocialProvider $provider, ProviderUser $profile, string $email, bool $verified, AccountType $type): User
    {
        [$first, $last] = $this->names($provider, $profile);

        $user = User::query()->create([
            'email' => $email,
            'password_hash' => null,
            'first_name' => $first,
            'last_name' => $last,
            'account_type' => $type,
        ]);

        if ($verified) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $this->audit->record('user.registered', $user, new: ['account_type' => $type->value, 'via' => $provider->value], actorUserId: $user->id);

        return $user;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function names(SocialProvider $provider, ProviderUser $profile): array
    {
        $raw = method_exists($profile, 'getRaw') ? (array) $profile->getRaw() : [];

        $first = $raw['given_name'] ?? $raw['first_name'] ?? $raw['name']['firstName'] ?? null;
        $last = $raw['family_name'] ?? $raw['last_name'] ?? $raw['name']['lastName'] ?? null;

        if ($first === null) {
            $parts = preg_split('/\s+/', trim((string) $profile->getName())) ?: [];
            $first = array_shift($parts) ?: null;
            $last ??= implode(' ', $parts) ?: null;
        }

        return [mb_substr($first ?: 'NaSeRy', 0, 100), mb_substr($last ?: 'User', 0, 100)];
    }

    private function emailVerified(SocialProvider $provider, ProviderUser $profile): bool
    {
        $raw = method_exists($profile, 'getRaw') ? (array) $profile->getRaw() : [];

        return match ($provider) {
            SocialProvider::Google, SocialProvider::Apple => filter_var($raw['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            // The Graph API only returns an email Facebook considers valid for the account.
            SocialProvider::Facebook => true,
        };
    }

    private function driver(SocialProvider $provider): Provider
    {
        $driver = Socialite::driver($provider->value)->stateless();

        if ($provider === SocialProvider::Apple) {
            $driver->scopes(['name', 'email']);

            if (method_exists($driver, 'cookieNonce')) {
                $driver->cookieNonce();
            }
        }

        return $driver;
    }

    private function sameBrowser(Request $request, string $flowHash): bool
    {
        $cookie = (string) $request->cookies->get(self::FLOW_COOKIE, '');

        return $cookie !== '' && hash_equals($flowHash, hash('sha256', $cookie));
    }

    private function flowCookie(string $flow, int $minutes, Request $request): Cookie
    {
        // Apple posts its callback cross-site, which only carries SameSite=None
        // cookies (HTTPS required). Plain-HTTP local development uses Lax.
        $secure = $request->isSecure();

        return Cookie::create(self::FLOW_COOKIE, $flow)
            ->withExpires(now()->addMinutes($minutes))
            ->withPath('/api/v1/auth/social')
            ->withDomain(config('session.domain'))
            ->withSecure($secure)
            ->withHttpOnly(true)
            ->withSameSite($secure ? Cookie::SAMESITE_NONE : Cookie::SAMESITE_LAX);
    }

    public function forgetFlowCookie(): Cookie
    {
        return Cookie::create(self::FLOW_COOKIE)
            ->withExpires(1)
            ->withPath('/api/v1/auth/social')
            ->withDomain(config('session.domain'));
    }

    private function frontend(string $path, array $query): string
    {
        return config('nasery.auth.frontend_url').$path.'?'.http_build_query($query);
    }
}
