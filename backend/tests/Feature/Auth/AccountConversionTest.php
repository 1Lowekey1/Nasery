<?php

namespace Tests\Feature\Auth;

use App\Enums\AuthCodePurpose;
use App\Enums\UserStatus;
use App\Mail\AuthCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** Signing up again with the e-mail of an account no longer in use (user decision 2026-10-27). */
class AccountConversionTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function payload(User $user, array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Rina', 'last_name' => 'Cortez', 'email' => strtoupper($user->email),
            'password' => 'NewSecret456', 'password_confirmation' => 'NewSecret456', 'account_type' => 'client',
        ], $overrides);
    }

    /** A business account that was on a team and got removed. */
    private function removedTeamMember(): User
    {
        $owner = $this->businessUser();
        $org = $this->onboard($owner);
        $staff = $this->businessUser(['password_hash' => 'Secret123']);
        $this->addStaff($org, $staff);
        DB::table('organization_members')->where('user_id', $staff->id)->update(['status' => 'deactivated', 'deactivated_at' => now()]);
        auth()->forgetGuards();

        return $staff;
    }

    public function test_removed_team_member_becomes_a_client_after_confirming_the_code(): void
    {
        $user = $this->removedTeamMember();

        $this->spaJson('POST', '/api/v1/auth/register', $this->payload($user))
            ->assertStatus(202)->assertJsonPath('data.conversion_required', true);
        $this->assertGuest();
        Mail::assertSent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->hasTo($user->email) && $m->purpose === AuthCodePurpose::AccountConversion);
        $this->assertSame('business', $user->refresh()->account_type->value, 'nothing changes before the code');

        $code = $this->lastCode(AuthCodePurpose::AccountConversion);
        $this->spaJson('POST', '/api/v1/auth/register', $this->payload($user, ['code' => $code]))
            ->assertCreated()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.account_type', 'client')
            ->assertJsonPath('data.email_verified', true);

        $user->refresh();
        $this->assertTrue(Hash::check('NewSecret456', $user->password_hash));
        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.converted', 'entity_id' => $user->id]);
        // History stays: the old team membership is still on record.
        $this->assertTrue(DB::table('organization_members')->where('user_id', $user->id)->exists());
    }

    public function test_wrong_code_changes_nothing(): void
    {
        $user = $this->removedTeamMember();
        $this->spaJson('POST', '/api/v1/auth/register', $this->payload($user))->assertStatus(202);

        $this->spaJson('POST', '/api/v1/auth/register', $this->payload($user, ['code' => '000000']))->assertStatus(422);

        $user->refresh();
        $this->assertSame('business', $user->account_type->value);
        $this->assertTrue(Hash::check('Secret123', $user->password_hash));
    }

    public function test_active_team_members_owners_suspended_and_admin_accounts_stay_taken(): void
    {
        $owner = $this->businessUser();
        $org = $this->onboard($owner);
        $staff = $this->businessUser();
        $this->addStaff($org, $staff);
        $suspended = $this->businessUser(['status' => UserStatus::Suspended]);
        $admin = $this->adminUser();
        auth()->forgetGuards();

        foreach ([$owner, $staff, $suspended, $admin] as $user) {
            $this->spaJson('POST', '/api/v1/auth/register', $this->payload($user))
                ->assertUnprocessable()
                ->assertJsonPath('error.details.fields.email.0', 'This email address is already registered.');
        }
        Mail::assertNotSent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->purpose === AuthCodePurpose::AccountConversion);
    }

    public function test_a_client_signing_up_again_as_client_is_told_to_sign_in(): void
    {
        $client = User::factory()->create();

        $this->spaJson('POST', '/api/v1/auth/register', $this->payload($client))
            ->assertUnprocessable()
            ->assertJsonPath('error.details.fields.email.0', 'This email address is already registered.');
    }
}
