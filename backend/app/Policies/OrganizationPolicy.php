<?php

namespace App\Policies;

use App\Enums\AccountType;
use App\Models\Organization;
use App\Models\User;
use App\Support\Authorization\OrganizationAccess;

/**
 * Organization-level rights (Laravel = primary authorization; RLS = backstop).
 * Settings, profile, services and resubmission are owner actions: the
 * permission catalog (0024) has no organization-settings key, and
 * co-owners are owners.
 */
class OrganizationPolicy
{
    public function __construct(private readonly OrganizationAccess $access) {}

    public function view(User $user, Organization $organization): bool
    {
        return $user->account_type === AccountType::Admin || $this->access->isMember($user, $organization);
    }

    public function manage(User $user, Organization $organization): bool
    {
        return $this->access->isOwner($user, $organization);
    }
}
