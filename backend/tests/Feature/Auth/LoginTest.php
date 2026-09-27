<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\User;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use InteractsWithAuth;

    public function test_user_can_sign_in(): void
    {
        $user = $this->userWithPassword();

        $this->spaJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret123'])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_is_case_insensitive(): void
    {
        $user = $this->userWithPassword();

        $this->spaJson('POST', '/api/v1/auth/login', ['email' => '  '.strtoupper($user->email), 'password' => 'Secret123'])
            ->assertOk();
    }

    public function test_wrong_password_and_unknown_email_look_identical(): void
    {
        $user = $this->userWithPassword();

        $wrong = $this->spaJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'Nope12345'])
            ->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
        $unknown = $this->spaJson('POST', '/api/v1/auth/login', ['email' => 'nobody@nasery.test', 'password' => 'Nope12345'])
            ->assertUnauthorized();

        $this->assertSame($wrong->json('error'), $unknown->json('error'));
        $this->assertGuest();
    }

    public function test_social_only_account_cannot_use_password_login(): void
    {
        $user = User::factory()->create(['password_hash' => null]);

        $this->spaJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => ''])
            ->assertUnprocessable();
        $this->spaJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'anything1'])
            ->assertUnauthorized();
    }

    public function test_suspended_and_deactivated_accounts_are_refused(): void
    {
        $suspended = $this->userWithPassword(attributes: []);
        $suspended->forceFill(['status' => UserStatus::Suspended])->save();
        $deactivated = $this->userWithPassword();
        $deactivated->forceFill(['status' => UserStatus::Deactivated])->save();

        $this->spaJson('POST', '/api/v1/auth/login', ['email' => $suspended->email, 'password' => 'Secret123'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');
        $this->spaJson('POST', '/api/v1/auth/login', ['email' => $deactivated->email, 'password' => 'Secret123'])
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_DEACTIVATED');

        $this->assertGuest();
    }

    public function test_deleted_accounts_cannot_sign_in(): void
    {
        $user = $this->userWithPassword();
        $user->delete(); // soft delete

        $this->spaJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret123'])
            ->assertUnauthorized();
    }

    public function test_one_account_is_protected_even_when_attackers_rotate_ips(): void
    {
        $user = $this->userWithPassword();
        config(['nasery.trusted_proxies' => '*']);
        [$max] = config('nasery.rate_limits.login-account');

        for ($i = 1; $i <= $max; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
                ->spaJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'Wrong123'], ['X-Forwarded-For' => "203.0.113.{$i}"])
                ->assertUnauthorized();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->spaJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret123'], ['X-Forwarded-For' => '203.0.113.200'])
            ->assertStatus(429);
    }

    public function test_me_requires_authentication(): void
    {
        $this->spaJson('GET', '/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }

    public function test_me_returns_the_signed_in_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->spaJson('GET', '/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.social_providers', []);
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->spaJson('POST', '/api/v1/auth/logout')->assertOk();

        $this->assertGuest('web');
    }

    public function test_suspension_takes_effect_on_a_live_session(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->actingAs($user)->spaJson('GET', '/api/v1/auth/me')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_SUSPENDED');

        $this->assertGuest('web');
    }

    public function test_profile_update_normalizes_phone_and_ignores_email(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->spaJson('PATCH', '/api/v1/auth/me', [
            'first_name' => 'Nathaniel',
            'phone' => '9171234567',
            'email' => 'hijack@nasery.test',
        ])->assertOk()
            ->assertJsonPath('data.first_name', 'Nathaniel')
            ->assertJsonPath('data.phone', '+639171234567')
            ->assertJsonPath('data.email', $user->email);

        $this->assertDatabaseHas('audit_logs', ['action' => 'user.profile_updated', 'entity_id' => $user->id]);
    }

    public function test_profile_update_rejects_blank_names(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->spaJson('PATCH', '/api/v1/auth/me', ['first_name' => ''])
            ->assertUnprocessable();
    }
}
