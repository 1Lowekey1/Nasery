<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Admin\AccountPurge;
use App\Services\Admin\AccountRemoval;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/**
 * Admin → Settings → Delete an account. The purge itself runs on the separate system
 * connection, which can't see a test's uncommitted rows, so it (and the backup) are
 * stubbed here: these tests check who may do it, what is blocked, the preview, and
 * that the right account and organizations are handed to the purge.
 */
class AccountRemovalTest extends TestCase
{
    use InteractsWithOrganizations;

    private function owner(): User
    {
        $admin = $this->adminUser();
        config(['nasery.hard_delete_admins' => [strtolower($admin->email)]]);

        return $admin;
    }

    public function test_only_the_listed_admin_may_delete_accounts(): void
    {
        $other = $this->adminUser();
        $client = User::factory()->create();
        config(['nasery.hard_delete_admins' => ['someone.else@nasery.test']]);

        $this->actingAs($other)->spaJson('GET', '/api/v1/admin/settings')->assertOk()->assertJsonPath('data.can_delete_accounts', false);
        $this->actingAs($other)->spaJson('GET', '/api/v1/admin/account-removal?email='.urlencode($client->email))->assertForbidden();
        $this->actingAs($other)->spaJson('POST', '/api/v1/admin/account-removal', ['email' => $client->email, 'confirm_email' => $client->email, 'reason' => 'Testing only'])->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $client->id]);

        // Not an admin at all: the admin area is closed.
        $this->actingAs($client)->spaJson('GET', '/api/v1/admin/account-removal?email='.urlencode($client->email))->assertForbidden();
    }

    public function test_preview_shows_what_goes_and_what_is_blocked(): void
    {
        $admin = $this->owner();
        [$org, $organizer] = $this->listedOrganizer();

        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/settings')->assertOk()->assertJsonPath('data.can_delete_accounts', true);
        $preview = $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/account-removal?email='.urlencode(strtoupper($organizer->email)))->assertOk()->json('data');
        $this->assertSame([$org->display_name], $preview['removes']['organizations']);
        $this->assertNull($preview['blocked']);

        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/account-removal?email='.urlencode($admin->email))->assertOk()
            ->assertJsonPath('data.blocked', "You can't delete your own account.");
        $activeAdmin = $this->adminUser();
        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/account-removal?email='.urlencode($activeAdmin->email))->assertOk()
            ->assertJsonPath('data.blocked', 'This is an active admin. Disable them on Admin Team first.');
        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/account-removal?email=nobody%40nasery.test')->assertNotFound();
    }

    public function test_removal_needs_the_typed_email_then_backs_up_purges_and_records_it(): void
    {
        $admin = $this->owner();
        [$org, $organizer] = $this->listedOrganizer();

        $purge = Mockery::mock(AccountPurge::class);
        $purge->shouldReceive('purge')->once()->withArgs(fn (Collection $users, Collection $orgs) => $users->all() === [$organizer->id] && $orgs->map(fn ($id) => (string) $id)->all() === [$org->id])
            ->andReturn(['users' => 1, 'organizations' => 1]);
        $this->app->instance(AccountPurge::class, $purge);
        $this->app->bind(AccountRemoval::class, fn ($app) => new class($app->make(AccountPurge::class), $app->make(\App\Support\Audit\AuditLogger::class), $app->make(\App\Support\Database\DatabaseContext::class)) extends AccountRemoval
        {
            protected function backup(): bool
            {
                return true;
            }
        });

        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/account-removal', ['email' => $organizer->email, 'confirm_email' => 'wrong@nasery.test', 'reason' => 'Old test account'])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.confirm_email.0', "Type the account's e-mail exactly to confirm.");
        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/account-removal', ['email' => $admin->email, 'confirm_email' => $admin->email, 'reason' => 'Old test account'])
            ->assertStatus(409);

        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/account-removal', ['email' => $organizer->email, 'confirm_email' => ' '.strtoupper($organizer->email), 'reason' => 'Old test account'])
            ->assertOk()->assertJsonPath('data.removed.users', 1);
        $row = DB::table('audit_logs')->where('action', 'account.deleted')->where('entity_id', $organizer->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, $row->actor_user_id);
        $this->assertSame('Old test account', $row->reason);
    }
}
