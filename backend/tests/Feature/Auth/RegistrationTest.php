<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthCodePurpose;
use App\Mail\AuthCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use InteractsWithAuth;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Sean',
            'middle_name' => 'Kenneth',
            'last_name' => 'Axalan',
            'email' => 'Reg+'.Str::lower(Str::random(10)).'@Nasery.test',
            'phone' => '0917 123 4567',
            'password' => 'Secret123',
            'password_confirmation' => 'Secret123',
            'account_type' => 'client',
        ], $overrides);
    }

    public function test_client_can_register_and_is_signed_in_unverified(): void
    {
        $payload = $this->payload();

        $response = $this->spaJson('POST', '/api/v1/auth/register', $payload)
            ->assertCreated()
            ->assertJsonPath('data.email', mb_strtolower($payload['email']))
            ->assertJsonPath('data.full_name', 'Sean Kenneth Axalan')
            ->assertJsonPath('data.phone', '+639171234567')
            ->assertJsonPath('data.account_type', 'client')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.email_verified', false)
            ->assertJsonPath('data.has_password', true)
            ->assertJsonMissingPath('data.password_hash');

        $user = User::query()->findOrFail($response->json('data.id'));
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('Secret123', $user->password_hash));
        $this->assertNull($user->email_verified_at);

        Mail::assertSent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->hasTo($user->email) && $m->purpose === AuthCodePurpose::EmailVerification);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.registered', 'entity_id' => $user->id, 'actor_user_id' => $user->id]);
    }

    public function test_business_account_can_register(): void
    {
        $this->spaJson('POST', '/api/v1/auth/register', $this->payload(['account_type' => 'business']))
            ->assertCreated()
            ->assertJsonPath('data.account_type', 'business');
    }

    public function test_admin_accounts_cannot_be_self_registered(): void
    {
        $this->spaJson('POST', '/api/v1/auth/register', $this->payload(['account_type' => 'admin']))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['account_type']]]]);
    }

    public function test_email_must_be_unique_case_insensitively(): void
    {
        $existing = User::factory()->create();

        $this->spaJson('POST', '/api/v1/auth/register', $this->payload(['email' => strtoupper($existing->email)]))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.email.0', 'This email address is already registered.');
    }

    public function test_password_rules_are_enforced(): void
    {
        $this->spaJson('POST', '/api/v1/auth/register', $this->payload(['password' => 'short', 'password_confirmation' => 'short']))
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['password']]]]);

        $this->spaJson('POST', '/api/v1/auth/register', $this->payload(['password' => 'allletters', 'password_confirmation' => 'allletters']))
            ->assertUnprocessable();

        $this->spaJson('POST', '/api/v1/auth/register', $this->payload(['password_confirmation' => 'Different123']))
            ->assertUnprocessable();
    }

    public function test_phone_is_optional_but_must_be_a_ph_mobile_number(): void
    {
        $this->spaJson('POST', '/api/v1/auth/register', $this->payload(['phone' => null]))
            ->assertCreated()->assertJsonPath('data.phone', null);

        $this->spaJson('POST', '/api/v1/auth/register', $this->payload(['phone' => '12345']))
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['phone']]]]);
    }

    public function test_required_fields(): void
    {
        $this->spaJson('POST', '/api/v1/auth/register', [])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['first_name', 'last_name', 'email', 'password', 'account_type']]]]);
    }

    public function test_failed_registration_leaves_nothing_behind(): void
    {
        $payload = $this->payload(['password_confirmation' => 'Mismatch123']);

        $this->spaJson('POST', '/api/v1/auth/register', $payload)->assertUnprocessable();

        $this->assertFalse(DB::table('users')->where('email', mb_strtolower($payload['email']))->exists());
        Mail::assertNothingQueued();
    }
}
