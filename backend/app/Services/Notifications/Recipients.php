<?php

namespace App\Services\Notifications;

use App\Enums\MembershipStatus;
use App\Models\Event;
use App\Models\Organization;
use App\Models\User;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Database\DatabaseContext;

/**
 * Who in an organization should hear about something: every active member
 * holding one of the permissions (owners hold all), organization-wide or,
 * for an event, granted on that event (B8). Reuses OrganizationAccess so
 * alerts follow exactly the same rules as the API.
 */
class Recipients
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly OrganizationAccess $access,
    ) {}

    /**
     * @param  list<string>  $permissions  any of these
     * @return list<string> user ids
     */
    public function forEvent(Event $event, array $permissions): array
    {
        return $this->resolve($event->organization_id, fn (User $u) => collect($permissions)->contains(fn ($p) => $this->access->canOnEvent($u, $event, $p)));
    }

    /**
     * @param  list<string>  $permissions  any of these
     * @return list<string> user ids
     */
    public function forOrganization(Organization|string $organization, array $permissions): array
    {
        return $this->resolve($organization instanceof Organization ? $organization->id : $organization,
            fn (User $u) => collect($permissions)->contains(fn ($p) => $this->access->can($u, $organization, $p)));
    }

    /**
     * @param  callable(User): bool  $allowed
     * @return list<string>
     */
    private function resolve(string $orgId, callable $allowed): array
    {
        return $this->db->system('notify.recipients', function () use ($orgId, $allowed) {
            $users = User::query()->whereIn('id', fn ($q) => $q->select('user_id')->from('organization_members')
                ->where('organization_id', $orgId)->where('status', MembershipStatus::Active->value))->get();

            return $users->filter($allowed)->pluck('id')->values()->all();
        });
    }
}
