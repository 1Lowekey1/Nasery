<?php

namespace App\Http\Resources;

use App\Models\Organization;
use App\Models\OrganizationRole;
use App\Support\Authorization\OrganizationAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization as seen by one of its members (or an admin).
 *
 * @mixin Organization
 */
class OrganizationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $access = app(OrganizationAccess::class);
        $member = $request->user() ? $access->membership($request->user(), $this->resource) : null;

        return [
            'id' => $this->id,
            'legal_name' => $this->legal_name,
            'display_name' => $this->display_name,
            'slug' => $this->slug,
            'approval_status' => $this->approval_status->value,
            'is_suspended' => $this->is_suspended,
            'suspended_reason' => $this->when($this->is_suspended, $this->suspended_reason),
            'is_operational' => $this->isOperational(),
            'my_membership' => $member ? [
                'member_id' => $member->id,
                'is_owner' => $member->is_owner,
                'is_primary_owner' => $member->is_primary_owner,
                'organization_role_id' => $member->organization_role_id,
                'role' => $member->is_owner ? 'Owner' : OrganizationRole::query()->whereKey($member->organization_role_id)->value('name'),
                // B8: drives which dashboard areas the member sees.
                'permissions' => $access->permissionKeys($request->user(), $this->resource),
                'sees_all_events' => $access->seesAllEvents($request->user(), $this->resource),
            ] : null,
            'profile' => new OrganizationProfileResource($this->whenLoaded('profile')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
