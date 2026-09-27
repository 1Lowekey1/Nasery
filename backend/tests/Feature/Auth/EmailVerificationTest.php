<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthCodePurpose;
use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\DefinesTestRoutes;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use DefinesTestRoutes, InteractsWithAuth;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $email = 'verify+'.Str::lower(Str::random(10)).'@nasery.test';
        $this->spaJson('POST', '/api/v1/auth/register', [
            'first_name' => 'Rylle', 'last_name' => 'Manco', 'email' => $email,
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'account_type' => 'client',
        ])->assertCreated();

        $this->user = User::query()->where('email', $email)->firstOrFail();
    }

    public function test_correct_code_verifies_the_email(): void
    {
        $this->actingAs($this->user)
            ->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $this->lastCode(AuthCodePurpose::EmailVerification)])
            ->assertOk()
            ->assertJsonPath('data.email_verified', true);

        $this->assertNotNull($this->user->refresh()->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.email_verified', 'entity_id' => $this->user->id]);
    }

    public function test_code_cannot_be_reused(): void
    {
        $code = $this->lastCode(AuthCodePurpose::EmailVerification);
        $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $code])->assertOk();

        $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $code])
            ->assertConflict()->assertJsonPath('error.code', 'EMAIL_ALREADY_VERIFIED');
    }

    public function test_wrong_codes_are_counted_even_though_the_request_rolls_back(): void
    {
        $code = $this->lastCode(AuthCodePurpose::EmailVerification);
        $max = config('nasery.auth.codes.max_attempts');

        for ($i = 1; $i <= $max; $i++) {
            $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $this->wrongCode($code)])
                ->assertUnprocessable()
                ->assertJsonPath('error.code', 'INVALID_CODE')
                ->assertJsonPath('error.details.attempts_remaining', $max - $i);
        }

        // Locked: even the right code is refused now.
        $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $code])
            ->assertStatus(429)->assertJsonPath('error.code', 'TOO_MANY_ATTEMPTS');
        $this->assertNull($this->user->refresh()->email_verified_at);
    }

    public function test_expired_code_is_refused(): void
    {
        $code = $this->lastCode(AuthCodePurpose::EmailVerification);

        $this->travel(config('nasery.auth.codes.ttl_minutes') + 1)->minutes();

        $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $code])
            ->assertUnprocessable()->assertJsonPath('error.code', 'CODE_EXPIRED');
    }

    public function test_resend_has_a_cooldown_and_replaces_the_old_code(): void
    {
        $old = $this->lastCode(AuthCodePurpose::EmailVerification);

        $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verification-code')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RESEND_COOLDOWN')
            ->assertJsonStructure(['error' => ['details' => ['retry_after_seconds']]]);

        $this->travel(config('nasery.auth.codes.resend_cooldown_seconds') + 1)->seconds();

        $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verification-code')->assertOk();
        $new = $this->lastCode(AuthCodePurpose::EmailVerification);

        if ($new !== $old) {
            $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $old])
                ->assertUnprocessable();
        }
        $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $new])->assertOk();
    }

    public function test_code_must_be_six_digits(): void
    {
        $this->actingAs($this->user)->spaJson('POST', '/api/v1/auth/email/verify', ['code' => '12ab'])
            ->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_verified_gate_blocks_until_verified(): void
    {
        $this->apiRoute('GET', 'needs-verified', fn () => ApiResponse::success(), ['auth:sanctum', 'active', 'verified']);

        $this->actingAs($this->user)->spaJson('GET', '/api/v1/_test/needs-verified')
            ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');

        $this->actingAs($this->user)
            ->spaJson('POST', '/api/v1/auth/email/verify', ['code' => $this->lastCode(AuthCodePurpose::EmailVerification)])
            ->assertOk();

        $this->actingAs($this->user->refresh())->spaJson('GET', '/api/v1/_test/needs-verified')->assertOk();
    }
}
