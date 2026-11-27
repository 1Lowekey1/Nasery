<?php

namespace Tests\Feature\Staff;

use App\Mail\AuthCodeMail;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use InteractsWithOrganizations;

    public function test_finished_tasks_carry_a_note_and_are_reviewed(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $helper = $this->join($owner, $org, ['preset' => 'checkin']);
        $event = $this->event($owner, $org);
        $eventMember = $this->assignToEvent($owner, $org, $event, $helper);
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $task = $this->actingAs($owner)->spaJson('POST', "{$base}/checklists", ['starter' => true])->json('data.checklists.0.tasks.0.id');
        $this->actingAs($owner)->spaJson('PUT', "{$base}/tasks/{$task}/assignees", ['event_member_ids' => [$eventMember]])->assertOk();
        $find = fn ($res) => collect($res->json('data.checklists.0.tasks'))->firstWhere('id', $task);

        // The assignee finishes it with a note: it waits for review. Only finished tasks can be reviewed.
        $this->actingAs($owner)->spaJson('POST', "{$base}/tasks/{$task}/review", ['decision' => 'verified'])->assertConflict();
        $done = $find($this->actingAs($helper)->spaJson('PATCH', "{$base}/tasks/{$task}", ['status' => 'done', 'completion_note' => 'Venue confirmed by phone'])->assertOk());
        $this->assertSame(['done', 'pending', 'Venue confirmed by phone'], [$done['status'], $done['review_status'], $done['completion_note']]);

        // Assignees can't review; the organizer asks for changes (note required): back in progress, assignee told.
        $this->actingAs($helper)->spaJson('POST', "{$base}/tasks/{$task}/review", ['decision' => 'verified'])->assertForbidden();
        $this->actingAs($owner)->spaJson('POST', "{$base}/tasks/{$task}/review", ['decision' => 'changes_requested'])->assertUnprocessable();
        $back = $find($this->actingAs($owner)->spaJson('POST', "{$base}/tasks/{$task}/review", ['decision' => 'changes_requested', 'note' => 'Get it in writing'])->assertOk());
        $this->assertSame(['in_progress', 'changes_requested', 'Get it in writing'], [$back['status'], $back['review_status'], $back['review_note']]);
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $helper->id)->where('type', 'task.reviewed')->count());

        // Done again, then verified.
        $this->actingAs($helper)->spaJson('PATCH', "{$base}/tasks/{$task}", ['status' => 'done', 'completion_note' => 'E-mail confirmation attached'])->assertOk();
        $ok = $find($this->actingAs($owner)->spaJson('POST', "{$base}/tasks/{$task}/review", ['decision' => 'verified'])->assertOk());
        $this->assertSame(['done', 'verified'], [$ok['status'], $ok['review_status']]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'task.verified', 'entity_id' => $task]);
    }

    private function invite(User $by, Organization $org, string $email, array $role = ['preset' => 'coordinator'])
    {
        return $this->actingAs($by)->spaJson('POST', "/api/v1/organizations/{$org->id}/team/invitations", ['email' => $email, ...$role]);
    }

    /** Invite + accept; returns the teammate. */
    private function join(User $owner, Organization $org, array $role = ['preset' => 'coordinator']): User
    {
        $staff = $this->businessUser();
        $this->invite($owner, $org, $staff->email, $role)->assertCreated();
        $id = $this->actingAs($staff)->spaJson('GET', '/api/v1/me/invitations')->json('data.0.id');
        $this->actingAs($staff)->spaJson('POST', "/api/v1/me/invitations/{$id}/accept")->assertOk();

        return $staff;
    }

    private function event(User $owner, Organization $org, string $name = 'Team Event'): string
    {
        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => $name,
            'sessions' => [['starts_at' => now('Asia/Manila')->addDays(20)->format('Y-m-d').'T16:00', 'ends_at' => now('Asia/Manila')->addDays(20)->format('Y-m-d').'T22:00']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    private function memberId(Organization $org, User $user): string
    {
        return DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $user->id)->whereNull('deleted_at')->value('id');
    }

    public function test_invited_account_accepts_and_gets_the_role_permissions(): void
    {
        [$org, $owner] = $this->listedOrganizer('Team Co');
        [$other, $otherOwner] = $this->listedOrganizer('Other Co');
        $staff = $this->businessUser();

        $this->invite($owner, $org, strtoupper($staff->email))->assertCreated()
            ->assertJsonPath('data.1.status', 'invited')->assertJsonPath('data.1.role', 'Coordinator');
        $this->invite($otherOwner, $other, $staff->email, ['preset' => 'sales'])->assertCreated();

        // Invited members have no access yet.
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/team")->assertStatus(404);
        $this->actingAs($staff)->spaJson('GET', '/api/v1/organizations/mine')->assertOk()->assertJsonCount(0, 'data');

        $invitations = $this->actingAs($staff)->spaJson('GET', '/api/v1/me/invitations')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $mine = collect($invitations)->firstWhere('organization', $org->display_name);
        $this->actingAs($staff)->spaJson('POST', "/api/v1/me/invitations/{$mine['id']}/accept")->assertOk()
            ->assertJsonPath('data.id', $org->id)
            ->assertJsonPath('data.my_membership.role', 'Coordinator')
            ->assertJsonPath('data.my_membership.sees_all_events', true)
            ->assertJsonPath('data.my_membership.permissions', ['attendee.manage', 'checkin.perform', 'checklist.manage', 'event.update', 'task.manage']);

        // One organization per account: the other invitation lapsed, and no new organization or second team.
        $this->actingAs($staff)->spaJson('GET', '/api/v1/me/invitations')->assertJsonCount(0, 'data');
        $this->actingAs($staff)->spaJson('POST', '/api/v1/organizations', $this->onboardingPayload())->assertConflict();
        $this->invite($otherOwner, $other, $staff->email)->assertConflict();
        $this->assertDatabaseHas('audit_logs', ['action' => 'staff.joined', 'actor_user_id' => $staff->id]);
    }

    public function test_invitation_rules(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $client = User::factory()->create();
        $pending = $this->businessUser();

        // A new e-mail needs a name (the invitation creates the account); a client account can't be invited (generic answer).
        $this->invite($owner, $org, 'nobody@nasery.test')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['first_name']]]]);
        $this->invite($owner, $org, $client->email)->assertConflict()
            ->assertJsonPath('error.message', "This e-mail can't be invited right now. Ask them for another address, or to leave their current team first.");
        $this->invite($owner, $org, $owner->email)->assertUnprocessable();
        $this->invite($owner, $org, $pending->email)->assertCreated();
        $this->invite($owner, $org, $pending->email)->assertConflict();

        // Declining removes it; they can be invited again later.
        $id = $this->actingAs($pending)->spaJson('GET', '/api/v1/me/invitations')->json('data.0.id');
        $this->actingAs($pending)->spaJson('POST', "/api/v1/me/invitations/{$id}/decline")->assertOk();
        $this->actingAs($pending)->spaJson('POST', "/api/v1/me/invitations/{$id}/accept")->assertNotFound();
        $this->invite($owner, $org, $pending->email)->assertCreated();

        // Someone else's invitation can't be accepted.
        $newId = $this->actingAs($pending)->spaJson('GET', '/api/v1/me/invitations')->json('data.0.id');
        $this->actingAs($this->businessUser())->spaJson('POST', "/api/v1/me/invitations/{$newId}/accept")->assertNotFound();
    }

    public function test_team_managers_cannot_escalate(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $leadRole = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/roles", [
            'name' => 'Team Lead', 'permissions' => ['staff.manage', 'inquiry.manage', 'quotation.manage'],
        ])->assertCreated()->json('data');
        $lead = $this->join($owner, $org, ['role_id' => collect($leadRole)->firstWhere('name', 'Team Lead')['id']]);

        $this->invite($lead, $org, $this->businessUser()->email, ['preset' => 'sales'])->assertCreated();          // holds both permissions
        $this->invite($lead, $org, $this->businessUser()->email, ['preset' => 'finance'])->assertForbidden();      // doesn't hold finance.view
        $this->actingAs($lead)->spaJson('POST', "/api/v1/organizations/{$org->id}/roles", ['name' => 'Boss', 'permissions' => ['staff.manage']])->assertForbidden();

        $ownerMember = $this->memberId($org, $owner);
        $this->actingAs($lead)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/team/{$ownerMember}")->assertForbidden();
        $this->actingAs($lead)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/team/{$this->memberId($org, $lead)}")->assertForbidden();

        // Someone without staff.manage can see the team but not change it.
        $coordinator = $this->join($owner, $org);
        $this->actingAs($coordinator)->spaJson('GET', "/api/v1/organizations/{$org->id}/team")->assertOk()->assertJsonPath('data.can_manage', false);
        $this->invite($coordinator, $org, $this->businessUser()->email)->assertForbidden();
    }

    public function test_deactivation_ends_access_and_event_assignments(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $staff = $this->join($owner, $org);
        $event = $this->event($owner, $org);
        $this->assignToEvent($owner, $org, $event, $staff);
        $member = $this->memberId($org, $staff);

        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/team/{$member}")->assertOk();
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}")->assertNotFound();
        $this->assertSame('deactivated', DB::table('event_members')->where('organization_member_id', $member)->value('status'));

        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/team/{$member}/reactivate")->assertOk();
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/team")->assertOk();
    }

    public function test_limited_staff_work_only_on_assigned_events(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $checker = $this->join($owner, $org, ['preset' => 'checkin']); // no organization-wide permissions
        $assigned = $this->event($owner, $org, 'Assigned Event');
        $other = $this->event($owner, $org, 'Other Event');

        $this->actingAs($checker)->spaJson('GET', "/api/v1/organizations/{$org->id}/events")->assertOk()->assertJsonCount(0, 'data');

        $this->assignToEvent($owner, $org, $assigned, $checker, ['task.manage']);
        $this->actingAs($checker)->spaJson('GET', "/api/v1/organizations/{$org->id}/events")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $assigned);
        $this->actingAs($checker)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$other}")->assertNotFound();

        // Event-level task.manage works on the assigned event only.
        $checklist = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$assigned}/checklists", ['starter' => true])->json('data.checklists.0.id');
        $this->actingAs($checker)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$assigned}/checklists/{$checklist}/tasks", ['name' => 'Print badges'])->assertCreated();
        $this->actingAs($checker)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/events/{$assigned}", ['name' => 'Renamed'])->assertForbidden();

        // Only event-level permissions are accepted per event.
        $memberId = $this->memberId($org, $checker);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$other}/team", [
            'organization_member_id' => $memberId, 'permissions' => ['finance.view'],
        ])->assertUnprocessable();
    }

    public function test_program_items_with_my_name_show_in_my_program(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $helper = $this->join($owner, $org, ['preset' => 'checkin']);
        $other = $this->join($owner, $org, ['preset' => 'checkin']);
        $event = $this->event($owner, $org);
        $this->assignToEvent($owner, $org, $event, $helper);
        $name = strtoupper(trim("{$helper->first_name} {$helper->last_name}"));

        foreach ([['Ceremony', " {$name} "], ['Dinner', 'Someone Else']] as [$title, $person]) {
            DB::table('event_activities')->insert(['event_id' => $event, 'organization_id' => $org->id, 'title' => $title,
                'person' => $person, 'created_by' => $owner->id]);
        }

        $url = "/api/v1/organizations/{$org->id}/my-program";
        $this->actingAs($helper)->spaJson('GET', $url)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Ceremony')->assertJsonPath('data.0.event_id', $event);
        $this->actingAs($other)->spaJson('GET', $url)->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->businessUser())->spaJson('GET', $url)->assertNotFound();
    }

    public function test_assigned_members_update_their_task_and_see_it_in_my_tasks(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $helper = $this->join($owner, $org, ['preset' => 'checkin']);
        $outsider = $this->join($owner, $org, ['preset' => 'checkin']);
        $event = $this->event($owner, $org);
        $eventMember = $this->assignToEvent($owner, $org, $event, $helper);
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $tasks = $this->actingAs($owner)->spaJson('POST', "{$base}/checklists", ['starter' => true])->json('data.checklists.0.tasks');

        $this->actingAs($owner)->spaJson('PUT', "{$base}/tasks/{$tasks[0]['id']}/assignees", ['event_member_ids' => [$eventMember]])->assertOk();
        $outsiderEventMember = DB::table('organization_members')->where('user_id', $outsider->id)->value('id'); // not on this event
        $this->actingAs($owner)->spaJson('PUT', "{$base}/tasks/{$tasks[1]['id']}/assignees", ['event_member_ids' => [$outsiderEventMember]])->assertUnprocessable();

        $this->actingAs($owner)->spaJson('GET', "{$base}/preparation")->assertJsonPath('data.checklists.0.tasks.0.assignee_ids', [$eventMember])
            ->assertJsonPath('data.team.0.id', $eventMember);

        // The assignee sees it in My Tasks and can move its status, nothing else.
        $this->actingAs($helper)->spaJson('GET', "/api/v1/organizations/{$org->id}/my-tasks")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $tasks[0]['id'])->assertJsonPath('data.0.event_id', $event);
        // Their finished task counts toward readiness once the organizer verifies it (0044).
        $this->actingAs($helper)->spaJson('PATCH', "{$base}/tasks/{$tasks[0]['id']}", ['status' => 'done'])->assertOk()
            ->assertJsonPath('data.readiness_percent', 0);
        $this->actingAs($owner)->spaJson('POST', "{$base}/tasks/{$tasks[0]['id']}/review", ['decision' => 'verified'])->assertOk()
            ->assertJsonPath('data.readiness_percent', 16.67);
        $this->actingAs($helper)->spaJson('PATCH', "{$base}/tasks/{$tasks[0]['id']}", ['name' => 'Renamed'])->assertForbidden();
        $this->actingAs($helper)->spaJson('PATCH', "{$base}/tasks/{$tasks[1]['id']}", ['status' => 'done'])->assertForbidden(); // not theirs

        // Leaving the event team unassigns their tasks.
        $this->actingAs($owner)->spaJson('DELETE', "{$base}/team/{$eventMember}")->assertOk();
        $this->actingAs($helper)->spaJson('GET', "/api/v1/organizations/{$org->id}/my-tasks")->assertJsonCount(0, 'data');
    }

    public function test_roles_can_be_managed(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $base = "/api/v1/organizations/{$org->id}/roles";

        $roles = $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Stage Crew', 'permissions' => ['checkin.perform', 'attendee.manage']])
            ->assertCreated()->json('data');
        $role = collect($roles)->firstWhere('name', 'Stage Crew');
        $this->assertSame(['attendee.manage', 'checkin.perform'], $role['permissions']);

        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Stage Crew', 'permissions' => []])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Bad', 'permissions' => ['do.anything']])->assertUnprocessable();

        $this->join($owner, $org, ['role_id' => $role['id']]);
        $this->actingAs($owner)->spaJson('DELETE', "{$base}/{$role['id']}")->assertConflict();
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$role['id']}", ['permissions' => ['checkin.perform']])->assertOk();
    }
    public function test_invitation_creates_the_account_and_the_link_joins_the_team(): void
    {
        Mail::fake();
        [$org, $owner] = $this->listedOrganizer();

        $this->invite($owner, $org, 'Rosa.New@nasery.test', ['preset' => 'coordinator', 'first_name' => 'Rosa', 'last_name' => 'Lim'])->assertCreated();
        $rosa = User::query()->where('email', 'rosa.new@nasery.test')->firstOrFail();
        $this->assertNull($rosa->password_hash);
        $this->assertSame('business', $rosa->account_type->value);
        $row = collect($this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/team")->json('data.members'))->firstWhere('user_id', $rosa->id);
        $this->assertSame('invited', $row['status']);
        $this->assertTrue($row['account_pending']);

        // One e-mail with the set-up link (organization and role shown), no second "invited" notice.
        $mail = null;
        Mail::assertSent(AuthCodeMail::class, function (AuthCodeMail $m) use (&$mail) {
            $mail = $m;

            return $m->hasTo('rosa.new@nasery.test');
        });
        $this->assertSame(['organization' => $org->display_name, 'role' => 'Coordinator'], $mail->team);
        $this->assertStringContainsString('/team-setup#', $mail->content()->with['setupUrl']);
        $this->assertSame(0, DB::table('notifications')->where('recipient_user_id', $rosa->id)->count());

        // Resend works while pending; a wrong code changes nothing.
        $memberId = $this->memberId($org, $rosa);
        $this->travel(2)->minutes();
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/team/{$memberId}/resend")->assertOk();
        Mail::assertSent(AuthCodeMail::class, 2);
        $this->spaJson('POST', '/api/v1/auth/team-setup', ['email' => $rosa->email, 'code' => '000000', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])->assertUnprocessable();

        $code = null;
        Mail::assertSent(AuthCodeMail::class, function (AuthCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->spaJson('POST', '/api/v1/auth/team-setup', ['email' => 'ROSA.new@nasery.test', 'code' => $code, 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertOk()->assertJsonPath('data.organization', $org->display_name);

        // Active, verified, on the team; the link can't be used twice.
        $rosa->refresh();
        $this->assertNotNull($rosa->email_verified_at);
        $this->assertSame('active', DB::table('organization_members')->where('id', $memberId)->value('status'));
        $this->spaJson('POST', '/api/v1/auth/team-setup', ['email' => $rosa->email, 'code' => $code, 'password' => 'Other1234', 'password_confirmation' => 'Other1234'])->assertUnprocessable();
        // They sign in with the new password and are a Coordinator on the team.
        $this->app['auth']->forgetGuards();
        $this->spaJson('POST', '/api/v1/auth/login', ['email' => $rosa->email, 'password' => 'NewPass123'])->assertOk();
        $this->spaJson('GET', '/api/v1/organizations/mine')->assertOk()->assertJsonPath('data.0.my_membership.role', 'Coordinator');
    }

    /** Invite fix (Nov 2026): the set-up page learns what its link is before showing the form. */
    public function test_the_invitation_link_reports_ready_used_or_invalid(): void
    {
        Mail::fake();
        [$org, $owner] = $this->listedOrganizer();
        $this->invite($owner, $org, 'tess.new@nasery.test', ['preset' => 'coordinator', 'first_name' => 'Tess', 'last_name' => 'Cruz'])->assertCreated();
        $code = null;
        Mail::assertSent(AuthCodeMail::class, function (AuthCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $this->app['auth']->forgetGuards();
        $check = fn (string $email, string $c) => $this->spaJson('POST', '/api/v1/auth/team-setup/check', ['email' => $email, 'code' => $c])->assertOk()->json('data.status');

        $this->assertSame('ready', $check('tess.new@nasery.test', $code));
        $this->assertSame('invalid', $check('tess.new@nasery.test', $code === '000000' ? '111111' : '000000'));
        $this->assertSame('invalid', $check('nobody@nasery.test', $code));
        $this->assertSame('ready', $check('tess.new@nasery.test', $code), 'checking never uses the link');

        $this->spaJson('POST', '/api/v1/auth/team-setup', ['email' => 'tess.new@nasery.test', 'code' => $code, 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])->assertOk();
        $this->assertSame('used', $check('tess.new@nasery.test', $code));
    }

    /** Invite fix (Nov 2026): an invitee who sets their first password with Forgot password still joins the team. */
    public function test_forgot_password_on_an_unused_invitation_joins_the_team(): void
    {
        Mail::fake();
        [$org, $owner] = $this->listedOrganizer();
        $this->invite($owner, $org, 'ana.new@nasery.test', ['preset' => 'coordinator', 'first_name' => 'Ana', 'last_name' => 'Reyes'])->assertCreated();
        $ana = User::query()->where('email', 'ana.new@nasery.test')->firstOrFail();
        $this->app['auth']->forgetGuards();

        $this->travel(2)->minutes();
        $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => 'ana.new@nasery.test'])->assertOk();
        $codes = [];
        Mail::assertSent(AuthCodeMail::class, function (AuthCodeMail $m) use (&$codes) {
            $codes[] = $m->code;

            return true;
        });
        $code = end($codes);

        $this->spaJson('POST', '/api/v1/auth/password/reset', ['email' => 'ana.new@nasery.test', 'code' => $code, 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
            ->assertOk()->assertJsonPath('message', "Password set. You're on the {$org->display_name} team. Sign in to start.");
        $this->assertSame('active', DB::table('organization_members')->where('id', $this->memberId($org, $ana))->value('status'));

        // A later reset is just a reset.
        $this->travel(2)->minutes();
        $this->spaJson('POST', '/api/v1/auth/password/forgot', ['email' => 'ana.new@nasery.test'])->assertOk();
        $codes = [];
        Mail::assertSent(AuthCodeMail::class, function (AuthCodeMail $m) use (&$codes) {
            $codes[] = $m->code;

            return true;
        });
        $this->spaJson('POST', '/api/v1/auth/password/reset', ['email' => 'ana.new@nasery.test', 'code' => end($codes), 'password' => 'Other1234', 'password_confirmation' => 'Other1234'])
            ->assertOk()->assertJsonPath('message', 'Password updated. Sign in with your new password.');
    }

    public function test_cancelled_invitation_removes_the_unused_account(): void
    {
        Mail::fake();
        [$org, $owner] = $this->listedOrganizer();
        $this->invite($owner, $org, 'temp@nasery.test', ['preset' => 'sales', 'first_name' => 'Tem', 'last_name' => 'Porary'])->assertCreated();
        $temp = User::query()->where('email', 'temp@nasery.test')->firstOrFail();

        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/team/{$this->memberId($org, $temp)}")->assertOk();
        $this->assertNotNull(DB::table('users')->where('id', $temp->id)->value('deleted_at'));
        // The address can be invited again.
        $this->invite($owner, $org, 'temp@nasery.test', ['preset' => 'sales', 'first_name' => 'Tem', 'last_name' => 'Porary'])->assertCreated();
    }

    public function test_removed_member_loses_access_and_open_tasks_are_handed_over(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $leaving = $this->join($owner, $org, ['preset' => 'checkin']);
        $staying = $this->join($owner, $org, ['preset' => 'checkin']);
        $event = $this->event($owner, $org);
        $eventMember = $this->assignToEvent($owner, $org, $event, $leaving);
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $tasks = $this->actingAs($owner)->spaJson('POST', "{$base}/checklists", ['starter' => true])->json('data.checklists.0.tasks');
        foreach ([0, 1, 2] as $i) {
            $this->actingAs($owner)->spaJson('PUT', "{$base}/tasks/{$tasks[$i]['id']}/assignees", ['event_member_ids' => [$eventMember]])->assertOk();
        }
        // One finished task stays theirs (history).
        $this->actingAs($leaving)->spaJson('PATCH', "{$base}/tasks/{$tasks[0]['id']}", ['status' => 'done', 'completion_note' => 'Booked'])->assertOk();

        $leavingId = $this->memberId($org, $leaving);
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/team/{$leavingId}/open-tasks")->assertOk()->assertJsonCount(2, 'data');

        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/team/{$leavingId}", ['reassign_to' => $this->memberId($org, $staying)])
            ->assertOk()->assertJsonPath('message', 'Team member removed. 2 open tasks were handed over.');

        // Access ends; the finished task keeps their name; the open ones are now the other member's.
        $this->actingAs($leaving)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}")->assertNotFound();
        $assignees = fn (string $task) => DB::table('task_assignees as ta')->join('event_members as em', 'em.id', '=', 'ta.event_member_id')
            ->join('organization_members as m', 'm.id', '=', 'em.organization_member_id')->where('ta.task_id', $task)->pluck('m.user_id')->all();
        $this->assertSame([$leaving->id], $assignees($tasks[0]['id']));
        $this->assertSame([$staying->id], $assignees($tasks[1]['id']));
        $this->assertSame([$staying->id], $assignees($tasks[2]['id']));

        // They're told, and their dashboard knows why.
        $this->actingAs($leaving)->spaJson('GET', '/api/v1/me/notifications')->assertJsonPath('data.0.type', 'staff.removed');
        $this->actingAs($leaving)->spaJson('GET', '/api/v1/me/former-team')->assertOk()
            ->assertJsonPath('data.organization', $org->display_name)->assertJsonPath('data.how', 'removed');
    }

    public function test_member_can_leave_and_open_tasks_need_a_new_assignee(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $quitter = $this->join($owner, $org, ['preset' => 'coordinator']);
        $event = $this->event($owner, $org);
        $eventMember = $this->assignToEvent($owner, $org, $event, $quitter);
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $task = $this->actingAs($owner)->spaJson('POST', "{$base}/checklists", ['starter' => true])->json('data.checklists.0.tasks.0.id');
        $this->actingAs($owner)->spaJson('PUT', "{$base}/tasks/{$task}/assignees", ['event_member_ids' => [$eventMember]])->assertOk();

        $this->actingAs($owner)->spaJson('POST', '/api/v1/me/team/leave')->assertForbidden(); // owners transfer ownership instead
        $this->actingAs($quitter)->spaJson('POST', '/api/v1/me/team/leave')->assertOk();

        $this->assertSame(0, DB::table('task_assignees')->where('task_id', $task)->count());
        $this->assertSame('deactivated', DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $quitter->id)->value('status'));
        $this->actingAs($owner)->spaJson('GET', '/api/v1/me/notifications')->assertJsonPath('data.0.type', 'staff.left')
            ->assertJsonPath('data.0.body', '1 of their open task needs a new assignee in Preparation.');
        $this->actingAs($quitter)->spaJson('GET', '/api/v1/me/former-team')->assertJsonPath('data.how', 'left');
        // Free to join another organization now.
        [$other, $otherOwner] = $this->listedOrganizer('Other Co');
        $this->invite($otherOwner, $other, $quitter->email)->assertCreated();
    }

    public function test_event_only_permissions_are_listed_for_the_team_portal(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $checker = $this->join($owner, $org, ['preset' => 'checkin']);
        $this->actingAs($checker)->spaJson('GET', '/api/v1/organizations/mine')->assertJsonPath('data.0.my_membership.event_permissions', []);
        $this->assignToEvent($owner, $org, $this->event($owner, $org), $checker, ['checkin.perform']);
        $this->actingAs($checker)->spaJson('GET', '/api/v1/organizations/mine')->assertJsonPath('data.0.my_membership.event_permissions', ['checkin.perform']);
    }
}
