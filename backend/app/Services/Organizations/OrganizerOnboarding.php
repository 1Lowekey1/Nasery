<?php

namespace App\Services\Organizations;

use App\Enums\ApprovalStatus;
use App\Enums\MembershipStatus;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\OrganizationProfile;
use App\Models\OrganizerApprovalRequest;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Services\Notifications\AdminAlerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Str;

/**
 * A business account creates its organization (workspace) and submits it
 * for approval in one step.
 *
 * RLS has no path for this (no INSERT policy on organizations; the first
 * membership can't satisfy "already a member"), so it runs in the system
 * context after Laravel's checks: verified business account, and at most
 * one owned organization per account (V1 decision).
 */
class OrganizerOnboarding
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AdminAlerts $adminAlerts,
    ) {}

    /**
     * @param  array{legal_name: string, display_name: string, profile: array<string, mixed>}  $data
     */
    public function create(User $owner, array $data): Organization
    {
        $organization = $this->db->system('org.onboard', function () use ($owner, $data) {
            // One organization per account, owned or joined as staff (B2 + B8).
            $alreadyMember = OrganizationMember::query()
                ->where('user_id', $owner->id)
                ->where('status', MembershipStatus::Active)
                ->exists();

            if ($alreadyMember) {
                throw new ApiException(ErrorCode::OrganizationExists, 'Your account already belongs to an organization.', 409);
            }

            $organization = Organization::query()->create([
                'legal_name' => $data['legal_name'],
                'display_name' => $data['display_name'],
                'slug' => $this->uniqueSlug($data['display_name']),
            ]);

            $member = new OrganizationMember([
                'organization_id' => $organization->id,
                'user_id' => $owner->id,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);
            $member->forceFill(['is_owner' => true, 'is_primary_owner' => true])->save();

            OwnershipTransfer::query()->create([
                'organization_id' => $organization->id,
                'from_member_id' => null,
                'to_member_id' => $member->id,
                'initiated_by' => $owner->id,
                'reason' => 'Initial owner (organization created)',
                'transferred_at' => now(),
            ]);

            OrganizationProfile::query()->create(['organization_id' => $organization->id] + $data['profile']);

            OrganizerApprovalRequest::query()->create([
                'organization_id' => $organization->id,
                'status' => ApprovalStatus::Pending,
                'submitted_at' => now(),
            ]);

            return $organization->refresh();
        });

        $this->audit->record('organization.created', $organization, new: [
            'display_name' => $organization->display_name,
            'slug' => $organization->slug,
        ], organizationId: $organization->id, actorUserId: $owner->id);
        $this->adminAlerts->applicationSubmitted($organization->id, $organization->display_name, false);

        return $organization;
    }

    /**
     * Slugs are unique among live organizations. Other tenants are invisible
     * under RLS, so this check is a system read.
     */
    public function uniqueSlug(string $name, ?string $ignoreOrganizationId = null): string
    {
        $base = Str::slug($name) ?: 'organizer';
        $base = Str::limit($base, 60, '');

        return $this->db->system('org.slug_check', function () use ($base, $ignoreOrganizationId) {
            $slug = $base;

            for ($i = 2; $this->slugTaken($slug, $ignoreOrganizationId); $i++) {
                $slug = "{$base}-{$i}";
            }

            return $slug;
        });
    }

    public function slugTaken(string $slug, ?string $ignoreOrganizationId = null): bool
    {
        return $this->db->system('org.slug_check', fn () => Organization::query()
            ->where('slug', $slug)
            ->when($ignoreOrganizationId, fn ($q) => $q->where('id', '!=', $ignoreOrganizationId))
            ->exists());
    }
}
