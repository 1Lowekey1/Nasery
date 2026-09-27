<?php

namespace App\Services\Staff;

use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\OrganizationMember;
use App\Models\Task;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Services\Notifications\Alerts;

/**
 * Who works on an event (event_members) and what they may do there
 * (event_member_permissions), plus task assignment (task_assignees).
 *
 * Event members are written in the system context after the controller's
 * staff.manage check: the RLS insert policy requires an event permission
 * that an organization owner, who isn't an event member, doesn't hold.
 */
class EventTeam
{
    /** Permissions that make sense per event (organization-level ones stay on roles). */
    public const EVENT_PERMISSIONS = ['event.update', 'checklist.manage', 'task.manage', 'ticket.manage', 'attendee.manage', 'checkin.perform'];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly OrganizationAccess $access,
        private readonly Alerts $alerts,
    ) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(Event $event): Collection
    {
        $rows = DB::table('event_members as em')
            ->join('organization_members as om', 'om.id', '=', 'em.organization_member_id')
            ->where('em.event_id', $event->id)->whereNull('em.deleted_at')
            ->orderBy('em.created_at')
            ->get(['em.id', 'em.organization_member_id', 'em.event_role_label', 'em.status', 'om.user_id']);

        $names = $rows->isEmpty() ? collect() : $this->db->system('event_team.names', fn () => DB::table('users')
            ->whereIn('id', $rows->pluck('user_id'))->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id'));
        $perms = $rows->isEmpty() ? collect() : DB::table('event_member_permissions as emp')->join('permissions as p', 'p.id', '=', 'emp.permission_id')
            ->whereIn('emp.event_member_id', $rows->pluck('id'))->get(['emp.event_member_id', 'p.key'])->groupBy('event_member_id');

        return $rows->map(fn ($r) => [
            'id' => $r->id,
            'organization_member_id' => $r->organization_member_id,
            'name' => ($u = $names[$r->user_id] ?? null) ? trim($u->first_name.' '.$u->last_name) : 'Unknown',
            'email' => $u->email ?? null,
            'label' => $r->event_role_label,
            'status' => $r->status,
            'permissions' => collect($perms[$r->id] ?? [])->pluck('key')->sort()->values(),
        ])->values();
    }

    /**
     * Add (or update) a team member on the event.
     *
     * @param  list<string>  $permissions
     */
    public function assign(Event $event, OrganizationMember $member, ?string $label, array $permissions, string $actorId): EventMember
    {
        if ($member->organization_id !== $event->organization_id || $member->status !== MembershipStatus::Active) {
            throw ValidationException::withMessages(['organization_member_id' => 'Choose an active member of your team.']);
        }

        if ($event->isTerminal()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This event has ended.', 409);
        }

        $unknown = array_diff($permissions, self::EVENT_PERMISSIONS);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['permissions' => 'These permissions can\'t be given per event: '.implode(', ', $unknown)]);
        }

        $eventMember = $this->db->system('event_team.assign', function () use ($event, $member, $label, $permissions, $actorId) {
            $row = EventMember::query()->where('event_id', $event->id)->where('organization_member_id', $member->id)->first()
                ?? (new EventMember)->forceFill(['id' => (string) Str::uuid7(), 'event_id' => $event->id, 'organization_member_id' => $member->id,
                    'organization_id' => $event->organization_id, 'assigned_by' => $actorId]);
            $row->forceFill(['event_role_label' => $label, 'status' => MembershipStatus::Active->value])->save();

            $ids = DB::table('permissions')->whereIn('key', $permissions)->pluck('id');
            DB::table('event_member_permissions')->where('event_member_id', $row->id)->delete();
            DB::table('event_member_permissions')->insert($ids->map(fn ($id) => [
                'id' => (string) Str::uuid7(), 'event_member_id' => $row->id, 'permission_id' => $id, 'created_at' => now(),
            ])->all());

            return $row;
        });

        $this->audit->record('event.team_member_assigned', $eventMember,
            new: ['event_id' => $event->id, 'member_id' => $member->id, 'label' => $label, 'permissions' => $permissions],
            organizationId: $event->organization_id);
        $this->access->flush();

        return $eventMember;
    }

    public function unassign(Event $event, EventMember $eventMember): void
    {
        if ($eventMember->event_id !== $event->id) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        $this->db->system('event_team.unassign', function () use ($eventMember) {
            DB::table('task_assignees')->where('event_member_id', $eventMember->id)->delete();
            DB::table('event_member_permissions')->where('event_member_id', $eventMember->id)->delete();
            $eventMember->forceFill(['deleted_at' => now()])->save();
        });

        $this->audit->record('event.team_member_removed', $eventMember, new: ['event_id' => $event->id], organizationId: $event->organization_id);
        $this->access->flush();
    }

    /**
     * Replace a task's assignees with these event members.
     *
     * @param  list<string>  $eventMemberIds
     */
    public function assignTask(Event $event, Task $task, array $eventMemberIds): void
    {
        $valid = DB::table('event_members')->where('event_id', $event->id)->whereIn('id', $eventMemberIds)
            ->where('status', MembershipStatus::Active->value)->whereNull('deleted_at')->pluck('id');

        if ($valid->count() !== count(array_unique($eventMemberIds))) {
            throw ValidationException::withMessages(['event_member_ids' => 'Assign the task to people on this event\'s team.']);
        }

        $before = DB::table('task_assignees')->where('task_id', $task->id)->pluck('event_member_id');
        DB::table('task_assignees')->where('task_id', $task->id)->delete();
        DB::table('task_assignees')->insert($valid->map(fn ($id) => [
            'id' => (string) Str::uuid7(), 'task_id' => $task->id, 'event_member_id' => $id, 'assigned_at' => now(), 'created_at' => now(),
        ])->all());

        $this->audit->record('task.assigned', $task, new: ['name' => $task->name, 'event_id' => $event->id, 'assignees' => $valid->count()],
            organizationId: $event->organization_id);
        $this->alerts->taskAssigned($event, $task, $valid->diff($before)->values()->all(), auth()->id());
    }

    /** Whether the user is assigned to this task (lets them update its status). */
    public function isAssignee(Event $event, Task $task, User $user): bool
    {
        $eventMemberId = $this->access->eventMemberId($user, $event);

        return $eventMemberId !== null && DB::table('task_assignees')->where('task_id', $task->id)->where('event_member_id', $eventMemberId)->exists();
    }
}
