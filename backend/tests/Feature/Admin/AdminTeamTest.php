<?php

namespace Tests\Feature\Admin;

use App\Enums\AuthCodePurpose;
use App\Mail\AuthCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class AdminTeamTest extends TestCase
{
    use InteractsWithAuth, InteractsWithOrganizations {
        InteractsWithOrganizations::businessUser insteadof InteractsWithAuth;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_admins_invite_admins_who_set_their_own_password(): void
    {
        $lead = User::factory()->admin()->create(['first_name' => 'Nathan', 'last_name' => 'Sevilla']);

        $this->actingAs($this->businessUser())->spaJson('POST', '/api/v1/admin/admins', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'ana@nasery.test'])->assertForbidden();
        $this->actingAs($lead)->spaJson('POST', '/api/v1/admin/admins', ['first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'Ana@Nasery.test'])
            ->assertCreated()->assertJsonPath('data.email', 'ana@nasery.test')->assertJsonPath('data.invited', true);
        $this->actingAs($lead)->spaJson('POST', '/api/v1/admin/admins', ['first_name' => 'Ana', 'last_name' => 'Two', 'email' => 'ana@nasery.test'])->assertUnprocessable();

        // No password yet: can't sign in. The e-mail says who added them.
        $ana = User::query()->where('email', 'ana@nasery.test')->firstOrFail();
        $this->assertNull($ana->password_hash);
        Mail::assertSent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->hasTo('ana@nasery.test') && $m->invitedBy === 'Nathan Sevilla'
            && $m->envelope()->subject === 'Nathan Sevilla added you as a NaSeRy administrator');
        $this->app['auth']->forgetGuards();
        $this->spaJson('POST', '/api/v1/auth/login', ['email' => 'ana@nasery.test', 'password' => 'Whatever123'])->assertUnauthorized();

        // She sets her own password with the code (the normal reset screen), then signs in as an admin.
        $this->spaJson('POST', '/api/v1/auth/password/reset', [
            'email' => 'ana@nasery.test', 'code' => $this->lastCode(AuthCodePurpose::PasswordReset),
            'password' => 'AnaChoseThis456', 'password_confirmation' => 'AnaChoseThis456',
        ])->assertOk();
        $this->spaJson('POST', '/api/v1/auth/login', ['email' => 'ana@nasery.test', 'password' => 'AnaChoseThis456'])->assertOk()
            ->assertJsonPath('data.account_type', 'admin')->assertJsonPath('data.email_verified', true);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin.invited', 'entity_id' => $ana->id, 'actor_user_id' => $lead->id]);

        // Back to the lead admin (drop Ana's signed-in session first).
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $list = collect($this->actingAs($lead)->spaJson('GET', '/api/v1/admin/admins')->assertOk()->json('data'))->keyBy('email');
        $this->assertFalse($list['ana@nasery.test']['invited']);
        $this->actingAs($lead)->spaJson('POST', "/api/v1/admin/admins/{$ana->id}/resend")->assertConflict(); // already has a password
    }

    public function test_resend_disable_and_enable(): void
    {
        $lead = User::factory()->admin()->create();
        $id = $this->actingAs($lead)->spaJson('POST', '/api/v1/admin/admins', ['first_name' => 'Ben', 'last_name' => 'Lim', 'email' => 'ben@nasery.test'])->json('data.id');

        $this->actingAs($lead)->spaJson('POST', "/api/v1/admin/admins/{$id}/resend")->assertStatus(429); // cooldown
        $this->travelTo(now()->addMinutes(2));
        $this->actingAs($lead)->spaJson('POST', "/api/v1/admin/admins/{$id}/resend")->assertOk();
        $this->assertCount(2, Mail::sent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->hasTo('ben@nasery.test')));

        // Disable: reason required, never yourself; they're blocked and told; enable again.
        $this->actingAs($lead)->spaJson('POST', "/api/v1/admin/admins/{$id}/status", ['status' => 'suspended'])->assertUnprocessable();
        $this->actingAs($lead)->spaJson('POST', "/api/v1/admin/admins/{$lead->id}/status", ['status' => 'suspended', 'reason' => 'Testing myself'])->assertConflict();
        $this->actingAs($lead)->spaJson('POST', "/api/v1/admin/admins/{$id}/status", ['status' => 'suspended', 'reason' => 'Left the team'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');
        $ben = User::query()->findOrFail($id);
        // Signed out everywhere and refused: 401 (session gone) or 403 (account suspended).
        $this->assertContains($this->actingAs($ben)->spaJson('GET', '/api/v1/admin/overview')->status(), [401, 403]);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $id)->where('type', 'account.notice')->count());
        $this->actingAs($lead)->spaJson('POST', "/api/v1/admin/admins/{$id}/status", ['status' => 'active', 'reason' => 'Back on the team'])
            ->assertOk()->assertJsonPath('data.status', 'active');

        // Only admin accounts can be managed here.
        $client = User::factory()->create();
        $this->actingAs($lead)->spaJson('POST', "/api/v1/admin/admins/{$client->id}/status", ['status' => 'suspended', 'reason' => 'Not an admin'])->assertNotFound();
    }

    public function test_the_first_admin_can_be_invited_from_the_server(): void
    {
        $this->artisan('nasery:admin:create', ['email' => 'first@nasery.test', '--first' => 'First', '--last' => 'Admin', '--invite' => true])
            ->assertSuccessful();
        $this->assertNull(User::query()->where('email', 'first@nasery.test')->value('password_hash'));
        Mail::assertSent(AuthCodeMail::class, fn (AuthCodeMail $m) => $m->hasTo('first@nasery.test') && $m->invitedBy === 'The NaSeRy team');
    }
}
