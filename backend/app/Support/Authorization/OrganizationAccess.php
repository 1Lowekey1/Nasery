<?php

namespace App\Support\Authorization;

use App\Enums\MembershipStatus;
use App\Enums\PermissionEffect;
use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Laravel-side organization authorization, in the locked order:
 *   1. active organization membership
 *   2. role permissions
 *   3. member permission overrides (grant / revoke)
 * Owners (is_owner, incl. the primary owner) hold every permission.
 * Event-scoped permissions (B8): an active event member also holds the
 * permissions granted on that event (event_member_permissions); canOnEvent()
 * combines both. Members without any organization-wide event permission
 * only see the events they are assigned to (seesAllEvents()).
 *
 * Queries run under the caller's RLS context, which already limits them to
 * the caller's own organizations. Results are memoized per request.
 */
class OrganizationAccess
{
    /** @var array<string, OrganizationMember|false> */
    private array $memberships = [];

    /** @var array<string, array<string, true>> */
    private array $permissions = [];

    /** @var array<string, string|null> */
    private array $eventMembers = [];

    public function membership(User $user, Organization|string $organization): ?OrganizationMember
    {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;
        $key = "{$user->id}:{$orgId}";

        if (! array_key_exists($key, $this->memberships)) {
            $this->memberships[$key] = OrganizationMember::query()
                ->where('organization_id', $orgId)
                ->where('user_id', $user->id)
                ->where('status', MembershipStatus::Active)
                ->first() ?? false;
        }

        return $this->memberships[$key] ?: null;
    }

    public function isMember(User $user, Organization|string $organization): bool
    {
        return $this->membership($user, $organization) !== null;
    }

    public function isOwner(User $user, Organization|string $organization): bool
    {
        return (bool) $this->membership($user, $organization)?->is_owner;
    }

    public function isPrimaryOwner(User $user, Organization|string $organization): bool
    {
        return (bool) $this->membership($user, $organization)?->is_primary_owner;
    }

    public function can(User $user, Organization|string $organization, string $permission): bool
    {
        $member = $this->membership($user, $organization);

        if ($member === null) {
            return false;
        }

        if ($member->is_owner) {
            return true;
        }

        return isset($this->effectivePermissions($member)[$permission]);
    }

    /**
     * @return array<string, true>
     */
    /** Organization-wide permissions that let a member see and work on every event. */
    public const EVENT_WIDE = ['event.create', 'event.update', 'event.publish', 'event.cancel', 'event.complete', 'staff.manage'];

    /**
     * Effective organization-wide permission keys (owners: every key).
     *
     * @return list<string>
     */
    public function permissionKeys(User $user, Organization|string $organization): array
    {
        $member = $this->membership($user, $organization);

        if ($member === null) {
            return [];
        }

        if ($member->is_owner) {
            return DB::table('permissions')->orderBy('key')->pluck('key')->all();
        }

        $keys = array_keys($this->effectivePermissions($member));
        sort($keys);

        return $keys;
    }

    /** The caller's active event membership id for $event, if any. */
    public function eventMemberId(User $user, Event $event): ?string
    {
        $member = $this->membership($user, $event->organization_id);

        if ($member === null) {
            return null;
        }

        $key = "event:{$event->id}:{$member->id}";

        if (! array_key_exists($key, $this->eventMembers)) {
            $this->eventMembers[$key] = DB::table('event_members')
                ->where('event_id', $event->id)->where('organization_member_id', $member->id)
                ->where('status', MembershipStatus::Active->value)->whereNull('deleted_at')
                ->value('id');
        }

        return $this->eventMembers[$key];
    }

    /** Organization-wide permission, or the same permission granted on this event. */
    public function canOnEvent(User $user, Event $event, string $permission): bool
    {
        if ($this->can($user, $event->organization_id, $permission)) {
            return true;
        }

        $eventMemberId = $this->eventMemberId($user, $event);

        return $eventMemberId !== null && DB::table('event_member_permissions as emp')
            ->join('permissions as p', 'p.id', '=', 'emp.permission_id')
            ->where('emp.event_member_id', $eventMemberId)->where('p.key', $permission)->exists();
    }

    /** Owners and members with an organization-wide event permission see every event. */
    public function seesAllEvents(User $user, Organization|string $organization): bool
    {
        return array_intersect(self::EVENT_WIDE, $this->permissionKeys($user, $organization)) !== [];
    }

    public function canSeeEvent(User $user, Event $event): bool
    {
        return $this->isMember($user, $event->organization_id)
            && ($this->seesAllEvents($user, $event->organization_id) || $this->eventMemberId($user, $event) !== null);
    }

    /** Forget memoized answers (after membership or role changes in the same request). */
    public function flush(): void
    {
        $this->memberships = [];
        $this->permissions = [];
        $this->eventMembers = [];
    }

    private function effectivePermissions(OrganizationMember $member): array
    {
        if (isset($this->permissions[$member->id])) {
            return $this->permissions[$member->id];
        }

        $keys = [];

        if ($member->organization_role_id !== null) {
            foreach (DB::table('role_permissions as rp')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.organization_role_id', $member->organization_role_id)
                ->pluck('p.key') as $key) {
                $keys[$key] = true;
            }
        }

        foreach (DB::table('member_permission_overrides as o')
            ->join('permissions as p', 'p.id', '=', 'o.permission_id')
            ->where('o.organization_member_id', $member->id)
            ->get(['p.key', 'o.effect']) as $override) {
            if ($override->effect === PermissionEffect::Grant->value) {
                $keys[$override->key] = true;
            } else {
                unset($keys[$override->key]);
            }
        }

        return $this->permissions[$member->id] = $keys;
    }
}
