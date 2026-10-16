<?php

namespace App\Services\Staff;

use App\Enums\AccountType;
use App\Enums\AuthCodePurpose;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Auth\AuthCode;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationRole;
use App\Models\User;
use App\Services\Auth\AuthCodeService;
use App\Services\Billing\Entitlements;
use App\Services\Notifications\Alerts;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Team members and roles (B8). User decisions: teammates are existing
 * business accounts invited by email (they accept after logging in), and an
 * account belongs to at most one organization.
 *
 * Writes that touch another user's row (the invite insert, whose trigger
 * reads users; accepting/declining, which the invitee can't do under RLS
 * while only "invited") run in the logged system context after the checks
 * here. Roles are organization-owned; PRESETS are templates copied on use.
 *
 * Escalation guard: a non-owner with staff.manage may only hand out
 * permissions they hold themselves, never staff.manage, and never change
 * owners or themselves.
 */
class StaffManager
{
    public const PRESETS = [
        'manager' => ['name' => 'Event Manager', 'description' => 'Runs events end to end.',
            'permissions' => ['event.create', 'event.update', 'event.publish', 'event.cancel', 'event.complete', 'checklist.manage', 'task.manage', 'ticket.manage', 'attendee.manage', 'checkin.perform']],
        'coordinator' => ['name' => 'Coordinator', 'description' => 'Handles preparation and the guest list on events.',
            'permissions' => ['event.update', 'checklist.manage', 'task.manage', 'attendee.manage', 'checkin.perform']],
        'sales' => ['name' => 'Sales', 'description' => 'Answers inquiries and sends quotations.',
            'permissions' => ['inquiry.manage', 'quotation.manage']],
        'checkin' => ['name' => 'Check-in Staff', 'description' => 'Scans tickets on event day (assigned events only).',
            'permissions' => []],
        'finance' => ['name' => 'Finance', 'description' => 'Views revenue and payment reports.',
            'permissions' => ['finance.view']],
    ];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly OrganizationAccess $access,
        private readonly Alerts $alerts,
        private readonly Entitlements $entitlements,
    ) {}

    // ------------------------------------------------------------------ roles

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function roles(Organization $organization): Collection
    {
        $roles = OrganizationRole::query()->where('organization_id', $organization->id)->orderBy('name')->get();
        $perms = $roles->isEmpty() ? collect() : DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->whereIn('rp.organization_role_id', $roles->pluck('id'))->get(['rp.organization_role_id', 'p.key'])->groupBy('organization_role_id');
        $counts = DB::table('organization_members')->where('organization_id', $organization->id)->whereNull('deleted_at')
            ->whereIn('status', ['active', 'invited'])->whereNotNull('organization_role_id')
            ->selectRaw('organization_role_id, count(*) as n')->groupBy('organization_role_id')->pluck('n', 'organization_role_id');

        return $roles->map(fn (OrganizationRole $r) => [
            'id' => $r->id,
            'name' => $r->name,
            'description' => $r->description,
            'permissions' => collect($perms[$r->id] ?? [])->pluck('key')->sort()->values(),
            'members' => (int) ($counts[$r->id] ?? 0),
        ])->values();
    }

    /**
     * @param  list<string>  $permissionKeys
     */
    public function createRole(Organization $organization, User $actor, string $name, ?string $description, array $permissionKeys): OrganizationRole
    {
        $this->assertGrantable($organization, $actor, $permissionKeys);

        $role = new OrganizationRole(['name' => trim($name), 'description' => $description]);
        $role->forceFill(['organization_id' => $organization->id])->save();
        $this->syncRolePermissions($role, $permissionKeys);

        $this->audit->record('staff.role_created', $role, new: ['name' => $role->name, 'permissions' => $permissionKeys], organizationId: $organization->id);

        return $role;
    }

    /** The organization's copy of a preset, created on first use. */
    public function presetRole(Organization $organization, User $actor, string $preset): OrganizationRole
    {
        $spec = self::PRESETS[$preset] ?? throw ValidationException::withMessages(['preset' => 'Unknown role preset.']);
        $existing = OrganizationRole::query()->where('organization_id', $organization->id)->where('name', $spec['name'])->first();

        return $existing ?? $this->createRole($organization, $actor, $spec['name'], $spec['description'], $spec['permissions']);
    }

    /**
     * @param  array{name?: string, description?: ?string, permissions?: list<string>}  $data
     */
    public function updateRole(Organization $organization, User $actor, OrganizationRole $role, array $data): OrganizationRole
    {
        if (isset($data['permissions'])) {
            $this->assertGrantable($organization, $actor, $data['permissions']);
        }

        $role->fill(array_intersect_key($data, array_flip(['name', 'description'])))->save();

        if (isset($data['permissions'])) {
            $this->syncRolePermissions($role, $data['permissions']);
        }

        $this->audit->record('staff.role_updated', $role, new: $data, organizationId: $organization->id);
        $this->access->flush();

        return $role;
    }

    public function deleteRole(Organization $organization, OrganizationRole $role): void
    {
        $inUse = DB::table('organization_members')->where('organization_role_id', $role->id)->whereNull('deleted_at')
            ->whereIn('status', ['active', 'invited'])->exists();

        if ($inUse) {
            throw new ApiException(ErrorCode::Conflict, 'Move the members with this role to another role first.', 409);
        }

        $role->delete();
        $this->audit->record('staff.role_removed', $role, new: ['name' => $role->name], organizationId: $organization->id);
    }

    // ---------------------------------------------------------------- members

    /**
     * Every membership row (active, invited, deactivated) with the person's
     * name and email. users rows are private under RLS: system read limited
     * to this organization's members.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function members(Organization $organization): Collection
    {
        $members = OrganizationMember::query()->where('organization_id', $organization->id)
            ->orderByDesc('is_owner')->orderBy('status')->orderBy('created_at')->get();
        $users = $this->db->system('staff.member_names', fn () => DB::table('users')->whereIn('id', $members->pluck('user_id'))
            ->get(['id', 'first_name', 'last_name', 'email', 'phone', 'password_hash'])->keyBy('id'));
        $roles = OrganizationRole::query()->whereIn('id', $members->pluck('organization_role_id')->filter())->pluck('name', 'id');

        return $members->map(fn (OrganizationMember $m) => [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'name' => ($u = $users[$m->user_id] ?? null) ? trim($u->first_name.' '.$u->last_name) : 'Unknown user',
            'email' => $u->email ?? null,
            'phone' => $u->phone ?? null,
            'status' => $m->status->value,
            // Invited by e-mail and hasn't opened the set-up link yet.
            'account_pending' => $m->status === MembershipStatus::Invited && ($users[$m->user_id] ?? null)?->password_hash === null,
            'is_owner' => $m->is_owner,
            'is_primary_owner' => $m->is_primary_owner,
            'role_id' => $m->organization_role_id,
            'role' => $m->is_owner ? 'Owner' : ($roles[$m->organization_role_id] ?? 'No role'),
            'joined_at' => $m->status === MembershipStatus::Invited ? null : $m->joined_at?->toIso8601String(),
            'invited_at' => $m->created_at?->toIso8601String(),
            'deactivated_at' => $m->deactivated_at?->toIso8601String(),
        ])->values();
    }

/**
     * Invite someone by e-mail. The invitation is the sign-up: when no NaSeRy
     * account uses the address yet, a business account without a password is
     * created and the e-mail carries a one-time set-up link (/team-setup), the
     * same way admins are onboarded. Opening it proves they own the mailbox;
     * choosing a password joins the team. Existing business accounts that
     * aren't on a team accept from their dashboard as before.
     */
    public function invite(Organization $organization, User $actor, string $email, OrganizationRole $role, ?string $firstName = null, ?string $lastName = null): OrganizationMember
    {
        $this->assertGrantable($organization, $actor, $this->rolePermissionKeys($role));
        $this->entitlements->assertRoom($organization, 'staff_limit');
        $email = Str::lower(trim($email));

        $member = $this->db->system('staff.invite', function () use ($organization, $actor, $email, $role, $firstName, $lastName) {
            $invitee = User::query()->whereRaw('lower(email) = ?', [$email])->whereNull('deleted_at')->first();

            if ($invitee !== null && $invitee->id === $actor->id) {
                throw ValidationException::withMessages(['email' => 'You are already on this team.']);
            }

            // Same answer for "a client account", "an admin" and "on another team": an invite
            // must not tell an organizer whether an address works somewhere else on NaSeRy.
            $unavailable = fn () => new ApiException(ErrorCode::Conflict,
                'This e-mail can\'t be invited right now. Ask them for another address, or to leave their current team first.', 409);

            if ($invitee !== null && $invitee->account_type !== AccountType::Business) {
                throw $unavailable();
            }

            if ($invitee === null) {
                if (blank($firstName) || blank($lastName)) {
                    throw ValidationException::withMessages(['first_name' => 'Add their first and last name: NaSeRy creates their account from this invitation.']);
                }
                $invitee = new User;
                $invitee->forceFill([
                    'email' => $email, 'first_name' => trim($firstName), 'last_name' => trim($lastName),
                    'account_type' => AccountType::Business, 'password_hash' => null,
                ])->save();
                $invitee->refresh();
            }

            $rows = OrganizationMember::query()->where('user_id', $invitee->id)->whereNull('deleted_at')->get();

            if ($rows->contains(fn ($m) => $m->status === MembershipStatus::Active)) {
                throw $unavailable();
            }

            if ($rows->contains(fn ($m) => $m->organization_id === $organization->id && $m->status === MembershipStatus::Invited)) {
                throw new ApiException(ErrorCode::Conflict, 'This person already has a pending invitation. Use "Resend" if they lost it.', 409);
            }

            // A previously deactivated membership in this organization is reused.
            $member = $rows->first(fn ($m) => $m->organization_id === $organization->id) ?? new OrganizationMember;
            $member->forceFill([
                'organization_id' => $organization->id,
                'user_id' => $invitee->id,
                'organization_role_id' => $role->id,
                'status' => MembershipStatus::Invited,
                'invited_by' => $actor->id,
                'deactivated_at' => null,
            ])->save();

            $this->audit->record('staff.invited', $member, new: ['email' => $email, 'role' => $role->name], organizationId: $organization->id);
            // New accounts get the set-up e-mail only (one e-mail, one link); existing ones the usual notice.
            if ($invitee->password_hash !== null) {
                $this->alerts->staffInvited($member, (string) $organization->display_name);
            }

            return $member->refresh();
        });

        $this->sendSetupLink($organization, $actor, $member);

        return $member;
    }

    /** E-mail (again) the invitation: the set-up link for someone without a password yet, otherwise a reminder. */
    public function resendInvite(Organization $organization, User $actor, OrganizationMember $member): void
    {
        if ($member->organization_id !== $organization->id || $member->status !== MembershipStatus::Invited) {
            throw new ApiException(ErrorCode::NotFound, 'This invitation is no longer pending.', 404);
        }
        if (! $this->sendSetupLink($organization, $actor, $member)) {
            $this->alerts->staffInvited($member, (string) $organization->display_name);
        }
        $this->audit->record('staff.invitation_resent', $member, organizationId: $organization->id);
    }

    /** @return bool whether a set-up link was sent (the invitee has no password yet) */
    private function sendSetupLink(Organization $organization, User $actor, OrganizationMember $member): bool
    {
        $invitee = $this->db->system('staff.invitee', fn () => User::query()->find($member->user_id));
        if ($invitee === null || $invitee->password_hash !== null) {
            return false;
        }
        $role = $member->organization_role_id ? OrganizationRole::query()->whereKey($member->organization_role_id)->value('name') : null;
        app(AuthCodeService::class)->issue($invitee, AuthCodePurpose::PasswordReset, trim("{$actor->first_name} {$actor->last_name}"),
            ['organization' => (string) $organization->display_name, 'role' => $role ?? 'Team member']);

        return true;
    }

    /**
     * /team-setup: the invitee opened the link and chose a password. Joins the
     * organization that invited them (their newest pending invitation).
     */
    public function completeSetup(User $user): ?Organization
    {
        $memberId = $this->db->system('staff.setup_invitation', fn () => OrganizationMember::query()->where('user_id', $user->id)
            ->where('status', MembershipStatus::Invited->value)->whereNull('deleted_at')->latest('created_at')->value('id'));

        return $memberId ? $this->respond($user, $memberId, true) : null;
    }

    /**
     * The signed-in user's pending invitations (they are not yet a member,
     * so RLS hides the organization: system read).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function invitationsFor(User $user): Collection
    {
        return $this->db->system('staff.my_invitations', fn () => DB::table('organization_members as m')
            ->join('organizations as o', 'o.id', '=', 'm.organization_id')
            ->leftJoin('organization_roles as r', 'r.id', '=', 'm.organization_role_id')
            ->leftJoin('users as u', 'u.id', '=', 'm.invited_by')
            ->where('m.user_id', $user->id)->where('m.status', MembershipStatus::Invited->value)->whereNull('m.deleted_at')
            ->whereNull('o.deleted_at')
            ->orderByDesc('m.created_at')
            ->get(['m.id', 'o.display_name as organization', 'r.name as role', 'u.first_name as inviter_first', 'u.last_name as inviter_last', 'm.created_at'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'organization' => $r->organization,
                'role' => $r->role ?? 'Team member',
                'invited_by' => trim(($r->inviter_first ?? '').' '.($r->inviter_last ?? '')) ?: null,
                'invited_at' => $r->created_at,
            ]));
    }

    public function respond(User $user, string $memberId, bool $accept): ?Organization
    {
        return $this->db->system('staff.respond_invitation', function () use ($user, $memberId, $accept) {
            $member = OrganizationMember::query()->whereKey($memberId)->where('user_id', $user->id)
                ->where('status', MembershipStatus::Invited->value)->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::NotFound, 'This invitation is no longer available.', 404);

            if (! $accept) {
                $member->forceFill(['deleted_at' => now()])->save();
                $this->audit->record('staff.invitation_declined', $member, organizationId: $member->organization_id);

                return null;
            }

            $elsewhere = OrganizationMember::query()->where('user_id', $user->id)->where('status', MembershipStatus::Active->value)
                ->whereNull('deleted_at')->exists();

            if ($elsewhere) {
                throw new ApiException(ErrorCode::Conflict, 'Your account already belongs to an organization.', 409);
            }

            $member->forceFill(['status' => MembershipStatus::Active, 'joined_at' => now()])->save();

            // One organization per account: other pending invitations lapse.
            OrganizationMember::query()->where('user_id', $user->id)->where('status', MembershipStatus::Invited->value)
                ->whereNull('deleted_at')->update(['deleted_at' => now(), 'updated_at' => now()]);

            $this->audit->record('staff.joined', $member, organizationId: $member->organization_id);
            $this->access->flush();

            return Organization::query()->find($member->organization_id);
        });
    }

    public function changeRole(Organization $organization, User $actor, OrganizationMember $member, OrganizationRole $role): OrganizationMember
    {
        $this->assertManageable($organization, $actor, $member);
        $this->assertGrantable($organization, $actor, $this->rolePermissionKeys($role));

        $old = $member->organization_role_id;
        $member->forceFill(['organization_role_id' => $role->id])->save();
        $this->audit->record('staff.role_changed', $member, old: ['role_id' => $old], new: ['role' => $role->name], organizationId: $organization->id);
        $this->access->flush();

        return $member;
    }

/**
     * Deactivate an active member (fired / left), or cancel a pending invitation.
     * Their open tasks go to $reassignTo when given, otherwise they become
     * unassigned ("Needs a new assignee"). History (finished tasks, check-ins,
     * audit) keeps their name; access ends at once (RLS only sees active members).
     */
    public function remove(Organization $organization, User $actor, OrganizationMember $member, ?OrganizationMember $reassignTo = null): array
    {
        $this->assertManageable($organization, $actor, $member);

        if ($member->status === MembershipStatus::Invited) {
            $member->forceFill(['deleted_at' => now()])->save();
            $this->audit->record('staff.invitation_cancelled', $member, organizationId: $organization->id);
            $this->dropUnusedAccount($member->user_id);

            return ['reassigned' => 0, 'unassigned' => 0];
        }

        if ($reassignTo !== null && ($reassignTo->organization_id !== $organization->id || $reassignTo->status !== MembershipStatus::Active || $reassignTo->id === $member->id)) {
            throw ValidationException::withMessages(['reassign_to' => 'Choose an active member of your team.']);
        }

        $result = $this->endMembership($member, $reassignTo, $actor->id);
        $this->audit->record('staff.deactivated', $member, new: $result, organizationId: $organization->id);
        $this->alerts->staffRemoved($member, (string) $organization->display_name);

        return $result;
    }

    /** A team member leaves on their own (owners transfer ownership instead). */
    public function leave(User $user): Organization
    {
        $member = OrganizationMember::query()->where('user_id', $user->id)->where('status', MembershipStatus::Active->value)
            ->whereNull('deleted_at')->first()
            ?? throw new ApiException(ErrorCode::NotFound, 'You are not on a team.', 404);

        if ($member->is_owner) {
            throw new ApiException(ErrorCode::Forbidden, 'Owners can\'t leave their own organization. Transfer ownership first.', 403);
        }

        $organization = Organization::query()->findOrFail($member->organization_id);
        $result = $this->endMembership($member, null, $user->id);
        $this->audit->record('staff.left', $member, new: $result, organizationId: $organization->id);
        $this->alerts->staffLeft($member, trim("{$user->first_name} {$user->last_name}"), $result['unassigned']);

        return $organization;
    }

    /**
     * Open tasks (not done or cancelled) assigned to the member, for the
     * "remove" confirmation.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function openTasks(Organization $organization, OrganizationMember $member): Collection
    {
        if ($member->organization_id !== $organization->id) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        return $this->openTaskRows($member->id)->map(fn ($t) => [
            'id' => $t->task_id, 'name' => $t->name, 'status' => $t->status,
            'due_at' => $t->due_at ? \Carbon\CarbonImmutable::parse($t->due_at)->toIso8601String() : null,
            'event' => ['id' => $t->event_id, 'name' => $t->event_name],
        ])->values();
    }

    private function openTaskRows(string $memberId): Collection
    {
        return $this->db->system('staff.open_tasks', fn () => DB::table('task_assignees as ta')
            ->join('event_members as em', 'em.id', '=', 'ta.event_member_id')
            ->join('tasks as t', 't.id', '=', 'ta.task_id')
            ->join('checklists as c', 'c.id', '=', 't.checklist_id')
            ->join('events as e', 'e.id', '=', 'c.event_id')
            ->where('em.organization_member_id', $memberId)
            ->whereNull('t.deleted_at')->whereNull('c.deleted_at')->whereNull('e.deleted_at')
            ->whereNotIn('t.status', ['done', 'cancelled'])->whereNotIn('e.status', ['completed', 'cancelled'])
            ->orderBy('t.due_at')
            ->get(['ta.id as assignment_id', 'ta.task_id', 't.name', 't.status', 't.due_at', 'e.id as event_id', 'e.name as event_name', 'em.event_role_label']));
    }

    /** @return array{reassigned: int, unassigned: int} */
    private function endMembership(OrganizationMember $member, ?OrganizationMember $reassignTo, string $actorId): array
    {
        $open = $this->openTaskRows($member->id);
        $reassigned = 0;

        $this->db->system('staff.end_membership', function () use ($member, $reassignTo, $actorId, $open, &$reassigned) {
            // Open work leaves with them; finished work keeps their name.
            DB::table('task_assignees')->whereIn('id', $open->pluck('assignment_id'))->delete();

            if ($reassignTo !== null) {
                foreach ($open->groupBy('event_id') as $eventId => $tasks) {
                    // The new person joins that event's team if needed (no extra permissions: assignees can work their own tasks).
                    $eventMemberId = DB::table('event_members')->where('event_id', $eventId)->where('organization_member_id', $reassignTo->id)->value('id');
                    if ($eventMemberId === null) {
                        $eventMemberId = (string) Str::uuid7();
                        DB::table('event_members')->insert(['id' => $eventMemberId, 'event_id' => $eventId, 'organization_member_id' => $reassignTo->id,
                            'organization_id' => $reassignTo->organization_id, 'event_role_label' => $tasks->first()->event_role_label,
                            'assigned_by' => $actorId, 'status' => MembershipStatus::Active->value, 'created_at' => now(), 'updated_at' => now()]);
                    } else {
                        DB::table('event_members')->where('id', $eventMemberId)->update(['status' => MembershipStatus::Active->value, 'updated_at' => now()]);
                    }
                    foreach ($tasks as $task) {
                        if (! DB::table('task_assignees')->where('task_id', $task->task_id)->where('event_member_id', $eventMemberId)->exists()) {
                            DB::table('task_assignees')->insert(['id' => (string) Str::uuid7(), 'task_id' => $task->task_id, 'event_member_id' => $eventMemberId,
                                'assigned_at' => now(), 'created_at' => now()]);
                        }
                        $reassigned++;
                    }
                }
            }

            $member->forceFill(['status' => MembershipStatus::Deactivated, 'deactivated_at' => now()])->save();
            // Their event assignments end with the membership.
            DB::table('event_members')->where('organization_member_id', $member->id)->where('status', MembershipStatus::Active->value)
                ->update(['status' => MembershipStatus::Deactivated->value, 'updated_at' => now()]);
        });

        if ($reassignTo !== null && $reassigned > 0) {
            $this->alerts->tasksHandedOver($reassignTo, $reassigned);
        }
        $this->access->flush();

        return ['reassigned' => $reassigned, 'unassigned' => $reassignTo === null ? $open->count() : 0];
    }

    /** A cancelled invitation's account that was never used (no password, no other team, nothing else) is removed. */
    private function dropUnusedAccount(string $userId): void
    {
        $this->db->system('staff.drop_unused_account', function () use ($userId) {
            $user = User::query()->find($userId);
            if ($user === null || $user->password_hash !== null || $user->account_type !== AccountType::Business) {
                return;
            }
            if (OrganizationMember::query()->where('user_id', $userId)->whereNull('deleted_at')->exists()) {
                return; // invited by someone else too
            }
            // Soft delete (the app role can't hard-delete users) and free the address for a new invitation.
            AuthCode::query()->where('user_id', $userId)->delete();
            DB::table('users')->where('id', $userId)->update([
                'deleted_at' => now(), 'updated_at' => now(), 'status' => 'deactivated',
                'email' => 'cancelled-invite-'.$userId.'@invalid.nasery',
            ]);
        });
    }

    /**
     * For a business account that isn't on a team: the team it was on last, so
     * the dashboard can say "You're no longer on X's team".
     *
     * @return array{organization: string, ended_at: ?string, how: string}|null
     */
    public function formerTeam(User $user): ?array
    {
        return $this->db->system('staff.former_team', function () use ($user) {
            if (OrganizationMember::query()->where('user_id', $user->id)->where('status', MembershipStatus::Active->value)->whereNull('deleted_at')->exists()) {
                return null;
            }
            $last = OrganizationMember::query()->where('user_id', $user->id)->where('status', MembershipStatus::Deactivated->value)
                ->whereNull('deleted_at')->latest('deactivated_at')->first();
            if ($last === null) {
                return null;
            }
            $left = DB::table('audit_logs')->where('entity_id', $last->id)->whereIn('action', ['staff.left', 'staff.deactivated'])->latest('created_at')->value('action') === 'staff.left';

            return [
                'organization' => (string) Organization::query()->whereKey($last->organization_id)->value('display_name'),
                'ended_at' => $last->deactivated_at?->toIso8601String(),
                'how' => $left ? 'left' : 'removed',
            ];
        });
    }

    public function reactivate(Organization $organization, User $actor, OrganizationMember $member): void
    {
        $this->assertManageable($organization, $actor, $member);

        if ($member->status !== MembershipStatus::Deactivated) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a deactivated member can be reactivated.', 409);
        }

        $elsewhere = $this->db->system('staff.reactivate_check', fn () => OrganizationMember::query()->where('user_id', $member->user_id)
            ->where('status', MembershipStatus::Active->value)->whereNull('deleted_at')->exists());

        if ($elsewhere) {
            throw new ApiException(ErrorCode::Conflict, 'This person has since joined another organization.', 409);
        }

        $member->forceFill(['status' => MembershipStatus::Active, 'deactivated_at' => null])->save();
        $this->audit->record('staff.reactivated', $member, organizationId: $organization->id);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return list<string>
     */
    public function rolePermissionKeys(OrganizationRole $role): array
    {
        return DB::table('role_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.organization_role_id', $role->id)->pluck('p.key')->all();
    }

    /**
     * @param  list<string>  $keys
     */
    private function syncRolePermissions(OrganizationRole $role, array $keys): void
    {
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id', 'key');

        if ($ids->count() !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['permissions' => 'One or more permissions are unknown.']);
        }

        DB::table('role_permissions')->where('organization_role_id', $role->id)->delete();
        DB::table('role_permissions')->insert($ids->values()->map(fn ($id) => [
            'id' => (string) Str::uuid7(), 'organization_role_id' => $role->id, 'permission_id' => $id, 'created_at' => now(),
        ])->all());
    }

    /**
     * @param  list<string>  $keys
     */
    private function assertGrantable(Organization $organization, User $actor, array $keys): void
    {
        if ($this->access->isOwner($actor, $organization)) {
            return;
        }

        $held = $this->access->permissionKeys($actor, $organization);

        if (in_array('staff.manage', $keys, true) || array_diff($keys, $held) !== []) {
            throw new ApiException(ErrorCode::Forbidden, 'You can only give teammates permissions you have yourself (and not team management).', 403);
        }
    }

    private function assertManageable(Organization $organization, User $actor, OrganizationMember $member): void
    {
        if ($member->organization_id !== $organization->id) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        if ($member->is_owner) {
            throw new ApiException(ErrorCode::Forbidden, 'Owners are managed through ownership transfer.', 403);
        }

        if ($member->user_id === $actor->id) {
            throw new ApiException(ErrorCode::Forbidden, 'You cannot change your own membership.', 403);
        }
    }
}
