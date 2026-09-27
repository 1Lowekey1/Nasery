<?php

namespace Tests\Feature\Staff;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class StaffTest extends TestCase
{
    use InteractsWithOrganizations;

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

        $this->invite($owner, $org, 'nobody@nasery.test')->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['email']]]]);
        $this->invite($owner, $org, $client->email)->assertUnprocessable();
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
        $this->actingAs($helper)->spaJson('PATCH', "{$base}/tasks/{$tasks[0]['id']}", ['status' => 'done'])->assertOk()
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
}
