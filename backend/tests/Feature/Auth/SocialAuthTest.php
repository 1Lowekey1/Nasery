<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\Auth\SocialAccount;
use App\Models\User;
use App\Services\Auth\SocialAuthService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as ProviderUser;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class SocialAuthTest extends TestCase
{
    use InteractsWithAuth;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-google-client',
            'services.google.client_secret' => 'test-google-secret',
            'services.facebook.client_id' => null,
            'nasery.auth.frontend_url' => 'http://localhost:3000',
        ]);
    }

    private function profile(string $email, bool $verified = true, ?string $id = null): ProviderUser
    {
        return (new ProviderUser)->setRaw([
            'sub' => $id ??= 'g-'.Str::random(12),
            'email' => $email,
            'email_verified' => $verified,
            'given_name' => 'Glenn',
            'family_name' => 'Sevilla',
        ])->map(['id' => $id, 'email' => $email, 'name' => 'Glenn Sevilla']);
    }

    /**
     * @return array{state: string, flow: string}
     */
    private function start(string $mode, ?string $type = null, string $provider = 'google'): array
    {
        $response = $this->spaJson('GET', "/api/v1/auth/social/{$provider}/redirect?".http_build_query(array_filter([
            'mode' => $mode, 'account_type' => $type,
        ])))->assertOk();

        parse_str((string) parse_url($response->json('data.redirect_url'), PHP_URL_QUERY), $query);

        return ['state' => $query['state'], 'flow' => $response->getCookie(SocialAuthService::FLOW_COOKIE, false)->getValue()];
    }

    private function providerCallback(string $state, ?string $flow, ProviderUser $profile, array $extra = []): TestResponse
    {
        Socialite::fake('google', $profile);
        $this->unencryptedCookies = [];

        $client = $flow === null ? $this : $this->withUnencryptedCookie(SocialAuthService::FLOW_COOKIE, $flow);

        return $client->get('/api/v1/auth/social/google/callback?'.http_build_query(['state' => $state, 'code' => 'provider-code'] + $extra));
    }

    /**
     * @return array<string, string>
     */
    private function redirectQuery(TestResponse $response): array
    {
        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return $query;
    }

    private function exchange(string $code, ?string $flow): TestResponse
    {
        $this->unencryptedCookies = [];
        $client = $flow === null ? $this->withCredentials() : $this->withCredentials()->withUnencryptedCookie(SocialAuthService::FLOW_COOKIE, $flow);

        return $client->spaJson('POST', '/api/v1/auth/social/exchange', ['code' => $code]);
    }

    public function test_providers_endpoint_reports_configuration(): void
    {
        $providers = collect($this->spaJson('GET', '/api/v1/auth/social/providers')->assertOk()->json('data'))->keyBy('provider');

        $this->assertTrue($providers['google']['enabled']);
        $this->assertFalse($providers['facebook']['enabled']);
    }

    public function test_unconfigured_provider_is_refused(): void
    {
        $this->spaJson('GET', '/api/v1/auth/social/facebook/redirect?mode=login')
            ->assertStatus(503)->assertJsonPath('error.code', 'SOCIAL_PROVIDER_NOT_CONFIGURED');
    }

    public function test_unknown_provider_is_not_found(): void
    {
        $this->spaJson('GET', '/api/v1/auth/social/myspace/redirect?mode=login')->assertNotFound();
    }

    public function test_register_mode_requires_an_account_type(): void
    {
        $this->spaJson('GET', '/api/v1/auth/social/google/redirect?mode=register')->assertUnprocessable();
    }

    public function test_full_signup_flow_creates_a_verified_account_and_session(): void
    {
        $email = 'social+'.Str::lower(Str::random(8)).'@nasery.test';
        ['state' => $state, 'flow' => $flow] = $this->start('register', 'business');

        $query = $this->redirectQuery($this->providerCallback($state, $flow, $this->profile($email)));
        $this->assertArrayHasKey('code', $query);

        $this->exchange($query['code'], $flow)
            ->assertOk()
            ->assertJsonPath('data.email', $email)
            ->assertJsonPath('data.first_name', 'Glenn')
            ->assertJsonPath('data.account_type', 'business')
            ->assertJsonPath('data.email_verified', true)
            ->assertJsonPath('data.has_password', false)
            ->assertJsonPath('data.social_providers', ['google']);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.registered', 'entity_id' => $user->id]);
    }

    public function test_callback_from_another_browser_is_rejected(): void
    {
        ['state' => $state] = $this->start('register', 'client');

        // Attacker forwards the callback URL: no flow cookie in the victim's browser.
        $query = $this->redirectQuery($this->providerCallback($state, null, $this->profile('attacker@nasery.test')));

        $this->assertSame('SOCIAL_LOGIN_EXPIRED', $query['social_error']);
        $this->assertFalse(User::query()->where('email', 'attacker@nasery.test')->exists());
    }

    public function test_exchange_code_only_works_once_and_only_in_the_same_browser(): void
    {
        $email = 'once+'.Str::lower(Str::random(8)).'@nasery.test';
        ['state' => $state, 'flow' => $flow] = $this->start('register', 'client');
        $code = $this->redirectQuery($this->providerCallback($state, $flow, $this->profile($email)))['code'];

        $this->exchange($code, 'some-other-browser-cookie')->assertUnprocessable()->assertJsonPath('error.code', 'SOCIAL_LOGIN_EXPIRED');
        // The failed attempt burned the code (single use).
        $this->exchange($code, $flow)->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_state_is_single_use(): void
    {
        ['state' => $state, 'flow' => $flow] = $this->start('register', 'client');
        $this->providerCallback($state, $flow, $this->profile('first+'.Str::random(6).'@nasery.test'));

        $query = $this->redirectQuery($this->providerCallback($state, $flow, $this->profile('replay@nasery.test')));

        $this->assertSame('SOCIAL_LOGIN_EXPIRED', $query['social_error']);
    }

    public function test_login_mode_does_not_create_accounts(): void
    {
        ['state' => $state, 'flow' => $flow] = $this->start('login');

        $response = $this->providerCallback($state, $flow, $this->profile('stranger@nasery.test'));

        $this->assertStringStartsWith('http://localhost:3000/login?', $response->headers->get('Location'));
        $this->assertSame('SOCIAL_ACCOUNT_NOT_REGISTERED', $this->redirectQuery($response)['social_error']);
    }

    public function test_verified_provider_email_links_an_existing_verified_account(): void
    {
        $user = $this->userWithPassword();
        ['state' => $state, 'flow' => $flow] = $this->start('login');

        $code = $this->redirectQuery($this->providerCallback($state, $flow, $this->profile($user->email)))['code'];
        $this->exchange($code, $flow)->assertOk()->assertJsonPath('data.id', $user->id);

        $this->assertTrue(Hash::check('Secret123', $user->refresh()->password_hash), 'Verified account keeps its password.');
        $this->assertTrue(SocialAccount::query()->where('user_id', $user->id)->exists());
    }

    public function test_linking_an_unverified_local_account_wipes_the_pre_registered_password(): void
    {
        // Attacker pre-registered the victim's address with their own password.
        $victim = $this->userWithPassword('Attacker123', ['email_verified_at' => null]);
        $this->fakeOtherSession($victim, 'attacker-session-id-000000000000000000000');

        ['state' => $state, 'flow' => $flow] = $this->start('login');
        $code = $this->redirectQuery($this->providerCallback($state, $flow, $this->profile($victim->email)))['code'];
        $this->exchange($code, $flow)->assertOk();

        $victim->refresh();
        $this->assertNull($victim->password_hash);
        $this->assertNotNull($victim->email_verified_at);
        $this->assertFalse($this->sessionExists('attacker-session-id-000000000000000000000'));
    }

    public function test_unverified_provider_email_never_links_to_an_existing_account(): void
    {
        $user = $this->userWithPassword();
        ['state' => $state, 'flow' => $flow] = $this->start('login');

        $query = $this->redirectQuery($this->providerCallback($state, $flow, $this->profile($user->email, verified: false)));

        $this->assertSame('SOCIAL_EMAIL_UNVERIFIED', $query['social_error']);
        $this->assertFalse(SocialAccount::query()->where('user_id', $user->id)->exists());
    }

    public function test_suspended_account_cannot_sign_in_socially(): void
    {
        $user = $this->userWithPassword();
        $user->forceFill(['status' => UserStatus::Suspended])->save();
        ['state' => $state, 'flow' => $flow] = $this->start('login');

        $this->assertSame('ACCOUNT_SUSPENDED', $this->redirectQuery($this->providerCallback($state, $flow, $this->profile($user->email)))['social_error']);
    }

    public function test_cancelled_at_provider_returns_a_clean_code(): void
    {
        ['state' => $state, 'flow' => $flow] = $this->start('register', 'client');

        $query = $this->redirectQuery($this->providerCallback($state, $flow, $this->profile('x@nasery.test'), ['error' => 'access_denied']));

        $this->assertSame('SOCIAL_LOGIN_CANCELLED', $query['social_error']);
    }

    public function test_provider_errors_do_not_leak_exception_text(): void
    {
        ['state' => $state, 'flow' => $flow] = $this->start('login');
        Socialite::fake('google', fn () => throw new \RuntimeException('internal token endpoint details'));
        $this->unencryptedCookies = [];

        $response = $this->withUnencryptedCookie(SocialAuthService::FLOW_COOKIE, $flow)
            ->get('/api/v1/auth/social/google/callback?state='.$state.'&code=x');

        $this->assertSame('SOCIAL_LOGIN_FAILED', $this->redirectQuery($response)['social_error']);
        $this->assertStringNotContainsString('internal', $response->headers->get('Location'));
    }

    public function test_cannot_unlink_the_only_sign_in_method(): void
    {
        $user = User::factory()->create(['password_hash' => null]);
        SocialAccount::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-1']);

        $this->actingAs($user)->spaJson('DELETE', '/api/v1/auth/social/google')
            ->assertConflict()->assertJsonPath('error.code', 'SOCIAL_UNLINK_BLOCKED');
    }

    public function test_can_unlink_when_a_password_exists(): void
    {
        $user = $this->userWithPassword();
        SocialAccount::query()->create(['user_id' => $user->id, 'provider' => 'google', 'provider_user_id' => 'g-2']);

        $this->actingAs($user)->spaJson('DELETE', '/api/v1/auth/social/google')
            ->assertOk()->assertJsonPath('data.social_providers', []);
    }
}
