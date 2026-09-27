<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthCodePurpose;
use App\Enums\UserStatus;
use App\Mail\AuthCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class PasswordTest extends TestCase
{
    use InteractsWithAuth;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_forgot_password_answers_identically_for_unknown_emails(): void
    {
        $user = $this->userWithPassword();

        $known = $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $unknown = $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => 'ghost@nasery.test'])->assertOk();

        $this->assertSame($known->json(), $unknown->json());
        Mail::assertSent(AuthCodeMail::class, 1);
        Mail::assertSent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->hasTo($user->email) && $m->purpose === AuthCodePurpose::PasswordReset);
    }

    public function test_repeat_requests_within_cooldown_stay_silent(): void
    {
        $user = $this->userWithPassword();

        $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();
        $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();

        Mail::assertSent(AuthCodeMail::class, 1);
    }

    public function test_suspended_accounts_get_no_reset_code(): void
    {
        $user = $this->userWithPassword();
        $user->forceFill(['status' => UserStatus::Suspended])->save();

        $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => $user->email])->assertOk();

        Mail::assertNothingQueued();
    }

    public function test_reset_changes_password_verifies_email_and_signs_out_everywhere(): void
    {
        $user = $this->userWithPassword(attributes: ['email_verified_at' => null]);
        $this->fakeOtherSession($user, 'other-device-session-id-000000000000000');

        $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => $user->email]);
        $code = $this->lastCode(AuthCodePurpose::PasswordReset);

        $this->spaJson('POST', '/api/v1/auth/password/reset', [
            'email' => $user->email, 'code' => $code, 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456',
        ])->assertOk();

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecret456', $user->password_hash));
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($this->sessionExists('other-device-session-id-000000000000000'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_reset', 'entity_id' => $user->id]);

        $this->spaJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'Secret123'])->assertUnauthorized();
        $this->spaJson('POST', '/api/v1/auth/login', ['email' => $user->email, 'password' => 'NewSecret456'])->assertOk();
    }

    public function test_reset_with_wrong_code_or_unknown_email_fails_the_same_way(): void
    {
        $user = $this->userWithPassword();
        $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => $user->email]);
        $code = $this->lastCode(AuthCodePurpose::PasswordReset);

        $wrong = $this->spaJson('POST', '/api/v1/auth/password/reset', [
            'email' => $user->email, 'code' => $this->wrongCode($code), 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_CODE');

        $unknown = $this->spaJson('POST', '/api/v1/auth/password/reset', [
            'email' => 'ghost@nasery.test', 'code' => $code, 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456',
        ])->assertUnprocessable()->assertJsonPath('error.code', 'INVALID_CODE');

        $this->assertSame($wrong->json('error.code'), $unknown->json('error.code'));
        $this->assertTrue(Hash::check('Secret123', $user->refresh()->password_hash));
    }

    public function test_reset_code_cannot_verify_email_and_vice_versa(): void
    {
        $user = $this->userWithPassword();
        $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => $user->email]);
        $resetCode = $this->lastCode(AuthCodePurpose::PasswordReset);

        $this->actingAs(User::query()->find($user->id)->forceFill(['email_verified_at' => null]))
            ->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $resetCode])
            ->assertUnprocessable();
    }

    public function test_change_password_requires_the_current_one(): void
    {
        $user = $this->userWithPassword();

        $this->actingAs($user)->spaJson('PUT', '/api/v1/auth/password', [
            'current_password' => 'Wrong1234', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456',
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['current_password']]]]);
    }

    public function test_change_password_keeps_this_device_and_signs_out_others(): void
    {
        $user = $this->userWithPassword();
        $this->fakeOtherSession($user, 'other-device-session-id-111111111111111');

        $this->actingAs($user)->spaJson('PUT', '/api/v1/auth/password', [
            'current_password' => 'Secret123', 'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewSecret456', $user->refresh()->password_hash));
        $this->assertFalse($this->sessionExists('other-device-session-id-111111111111111'));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.password_changed', 'entity_id' => $user->id]);
    }

    public function test_new_password_must_differ_and_follow_the_policy(): void
    {
        $user = $this->userWithPassword();

        $this->actingAs($user)->spaJson('PUT', '/api/v1/auth/password', [
            'current_password' => 'Secret123', 'password' => 'Secret123', 'password_confirmation' => 'Secret123',
        ])->assertUnprocessable();

        $this->actingAs($user)->spaJson('PUT', '/api/v1/auth/password', [
            'current_password' => 'Secret123', 'password' => 'short', 'password_confirmation' => 'short',
        ])->assertUnprocessable();
    }

    public function test_social_only_account_can_set_a_first_password(): void
    {
        $user = User::factory()->create(['password_hash' => null]);

        $this->actingAs($user)->spaJson('PUT', '/api/v1/auth/password', [
            'password' => 'FirstPass123', 'password_confirmation' => 'FirstPass123',
        ])->assertOk();

        $this->assertTrue(Hash::check('FirstPass123', $user->refresh()->password_hash));
    }
}
