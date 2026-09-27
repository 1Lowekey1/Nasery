<?php

namespace App\Services\Staff;

use App\Enums\AccountType;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationRole;
use App\Models\User;
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
            ->get(['id', 'first_name', 'last_name', 'email', 'phone'])->keyBy('id'));
        $roles = OrganizationRole::query()->whereIn('id', $members->pluck('organization_role_id')->filter())->pluck('name', 'id');

        return $members->map(fn (OrganizationMember $m) => [
            'id' => $m->id,
            'user_id' => $m->user_id,
            'name' => ($u = $users[$m->user_id] ?? null) ? trim($u->first_name.' '.$u->last_name) : 'Unknown user',
            'email' => $u->email ?? null,
            'phone' => $u->phone ?? null,
            'status' => $m->status->value,
            'is_owner' => $m->is_owner,
            'is_primary_owner' => $m->is_primary_owner,
            'role_id' => $m->organization_role_id,
            'role' => $m->is_owner ? 'Owner' : ($roles[$m->organization_role_id] ?? 'No role'),
            'joined_at' => $m->status === MembershipStatus::Invited ? null : $m->joined_at?->toIso8601String(),
            'invited_at' => $m->created_at?->toIso8601String(),
            'deactivated_at' => $m->deactivated_at?->toIso8601String(),
        ])->values();
    }

    public function invite(Organization $organization, User $actor, string $email, OrganizationRole $role): OrganizationMember
    {
        $this->assertGrantable($organization, $actor, $this->rolePermissionKeys($role));
        $this->entitlements->assertRoom($organization, 'staff_limit');
        $email = Str::lower(trim($email));

        return $this->db->system('staff.invite', function () use ($organization, $actor, $email, $role) {
            $invitee = User::query()->whereRaw('lower(email) = ?', [$email])->first();

            if ($invitee === null || $invitee->account_type !== AccountType::Business) {
                throw ValidationException::withMessages(['email' => 'No NaSeRy business account uses this email. Ask them to sign up as an organizer or team member first.']);
            }

            if ($invitee->id === $actor->id) {
                throw ValidationException::withMessages(['email' => 'You are already on this team.']);
            }

            $rows = OrganizationMember::query()->where('user_id', $invitee->id)->whereNull('deleted_at')->get();

            if ($rows->contains(fn ($m) => $m->status === MembershipStatus::Active)) {
                throw new ApiException(ErrorCode::Conflict, 'This person already belongs to an organization on NaSeRy.', 409);
            }

            if ($rows->contains(fn ($m) => $m->organization_id === $organization->id && $m->status === MembershipStatus::Invited)) {
                throw new ApiException(ErrorCode::Conflict, 'This person already has a pending invitation.', 409);
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
            $this->alerts->staffInvited($member, (string) $organization->display_name);

            return $member->refresh();
        });
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

    /** Deactivate an active member, or cancel a pending invitation. */
    public function remove(Organization $organization, User $actor, OrganizationMember $member): void
    {
        $this->assertManageable($organization, $actor, $member);

        if ($member->status === MembershipStatus::Invited) {
            $member->forceFill(['deleted_at' => now()])->save();
            $this->audit->record('staff.invitation_cancelled', $member, organizationId: $organization->id);

            return;
        }

        $member->forceFill(['status' => MembershipStatus::Deactivated, 'deactivated_at' => now()])->save();
        // Their event assignments end with the membership.
        $this->db->system('staff.deactivate_event_members', fn () => DB::table('event_members')
            ->where('organization_member_id', $member->id)->where('status', MembershipStatus::Active->value)
            ->update(['status' => MembershipStatus::Deactivated->value, 'updated_at' => now()]));

        $this->audit->record('staff.deactivated', $member, organizationId: $organization->id);
        $this->access->flush();
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
