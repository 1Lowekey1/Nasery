<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\DefinesTestRoutes;
use Tests\Support\EnforcedCsrf;
use Tests\TestCase;

/**
 * Proves the Sanctum SPA protections with CSRF verification actually on.
 */
class CsrfAndSessionTest extends TestCase
{
    use DefinesTestRoutes;

    private const SPA = ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.middleware.validate_csrf_token' => EnforcedCsrf::class]);

        $this->apiRoute('POST', 'mutate', fn (Request $request) => ApiResponse::success([
            'user' => $request->user()?->getAuthIdentifier(),
        ]));
    }

    public function test_spa_post_without_csrf_token_is_rejected(): void
    {
        $this->postJson('/api/v1/_test/mutate', [], self::SPA)
            ->assertStatus(419)
            ->assertJsonPath('error.code', 'CSRF_TOKEN_MISMATCH');
    }

    public function test_spa_post_with_wrong_csrf_token_is_rejected(): void
    {
        $this->withSession(['_token' => 'the-real-token'])
            ->postJson('/api/v1/_test/mutate', [], self::SPA + ['X-CSRF-TOKEN' => 'forged'])
            ->assertStatus(419);
    }

    public function test_spa_post_with_matching_csrf_token_passes(): void
    {
        $this->withSession(['_token' => 'the-real-token'])
            ->postJson('/api/v1/_test/mutate', [], self::SPA + ['X-CSRF-TOKEN' => 'the-real-token'])
            ->assertOk();
    }

    public function test_full_xsrf_cookie_flow_passes(): void
    {
        config(['session.driver' => 'database']);
        $this->apiRoute('POST', 'session-id', fn (Request $request) => ApiResponse::success(['id' => $request->session()->getId()]));

        $cookie = $this->get('/sanctum/csrf-cookie', self::SPA)->assertNoContent();
        $issuedId = $cookie->getCookie(config('session.cookie'))->getValue();

        $session = $cookie->getCookie(config('session.cookie'), false)->getValue();
        $xsrf = $cookie->getCookie('XSRF-TOKEN', false)->getValue();
        $this->freshProcess();

        // Replay exactly what a browser would send back (raw, encrypted cookies).
        $this->withCredentials()->withUnencryptedCookies([config('session.cookie') => $session, 'XSRF-TOKEN' => $xsrf])
            ->postJson('/api/v1/_test/session-id', [], self::SPA + ['X-XSRF-TOKEN' => $xsrf])
            ->assertOk()
            ->assertJsonPath('data.id', $issuedId); // the cookie's session was really loaded
    }

    public function test_cross_port_same_site_request_still_needs_a_token(): void
    {
        // localhost:3000 -> localhost:8000 is "same-site" but not same-origin.
        $this->postJson('/api/v1/_test/mutate', [], self::SPA + ['Sec-Fetch-Site' => 'same-site'])
            ->assertStatus(419);
    }

    /**
     * Log a user in through a real first-party request with a persistent
     * session store, and return the raw cookies a browser would hold.
     *
     * @return array{session: string, xsrf: string}
     */
    private function browserLogin(User $user): array
    {
        config(['session.driver' => 'database']);

        // Guests can't see users rows under RLS, so a login lookup must run in
        // the system context (as B1's real login action will).
        $this->apiRoute('POST', 'login-as/{id}', function (string $id, DatabaseContext $context) {
            $loggedIn = $context->system('test.login', fn () => Auth::guard('web')->loginUsingId($id));
            abort_unless($loggedIn, 500, 'test login failed');
            request()->session()->regenerate();

            return ApiResponse::success();
        });

        $csrf = $this->get('/sanctum/csrf-cookie', self::SPA);
        $session = $csrf->getCookie(config('session.cookie'), false)->getValue();
        $xsrf = $csrf->getCookie('XSRF-TOKEN', false)->getValue();
        $this->freshProcess();

        $login = $this->withCredentials()->withUnencryptedCookies([config('session.cookie') => $session, 'XSRF-TOKEN' => $xsrf])
            ->postJson("/api/v1/_test/login-as/{$user->id}", [], self::SPA + ['X-XSRF-TOKEN' => $xsrf])
            ->assertOk();
        $this->freshProcess();

        return [
            'session' => $login->getCookie(config('session.cookie'), false)->getValue(),
            'xsrf' => $login->getCookie('XSRF-TOKEN', false)->getValue(),
        ];
    }

    /** Forget in-memory session/auth state, as a new PHP request would. */
    private function freshProcess(): void
    {
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = $this->unencryptedCookies = [];
    }

    public function test_session_authenticates_first_party_requests(): void
    {
        $user = User::factory()->create();
        $cookies = $this->browserLogin($user);

        $this->withCredentials()->withUnencryptedCookies([config('session.cookie') => $cookies['session'], 'XSRF-TOKEN' => $cookies['xsrf']])
            ->postJson('/api/v1/_test/mutate', [], self::SPA + ['X-XSRF-TOKEN' => $cookies['xsrf']])
            ->assertOk()
            ->assertJsonPath('data.user', $user->id);
    }

    public function test_session_cookie_is_ignored_without_first_party_origin(): void
    {
        $user = User::factory()->create();
        $cookies = $this->browserLogin($user);

        // A valid logged-in session cookie, but the request doesn't come from
        // a first-party origin: Sanctum must not load the session at all, so
        // a cross-site page can't ride the user's cookie.
        $this->withCredentials()->withUnencryptedCookies([config('session.cookie') => $cookies['session']])
            ->postJson('/api/v1/_test/mutate', [], ['Origin' => 'https://evil.example', 'Referer' => 'https://evil.example/'])
            ->assertOk()
            ->assertJsonPath('data.user', null);
    }

    public function test_stolen_session_cookie_without_csrf_token_is_rejected(): void
    {
        $user = User::factory()->create();
        $cookies = $this->browserLogin($user);

        // Forged request that claims the SPA origin but has no XSRF header.
        $this->withCredentials()->withUnencryptedCookies([config('session.cookie') => $cookies['session']])
            ->postJson('/api/v1/_test/mutate', [], self::SPA)
            ->assertStatus(419);
    }
}
