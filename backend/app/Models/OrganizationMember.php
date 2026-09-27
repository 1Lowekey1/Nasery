<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * public.organization_members (0004). Ownership flags (is_owner,
 * is_primary_owner) are not app-writable (0028): the primary owner changes
 * only via transfer_organization_ownership(); the first owner is created in
 * the system context during onboarding. primary_owner_marker is a generated
 * column and never written.
 */
#[Fillable(['organization_id', 'user_id', 'organization_role_id', 'status', 'invited_by', 'joined_at'])]
class OrganizationMember extends Model
{
    use SoftDeletes;

    /**
     * Only columns nasery_app may INSERT (0028); everything else comes from
     * the column defaults, so re-read a new member before using it.
     */
    protected $attributes = [
        'organization_role_id' => null,
        'status' => 'active',
        'invited_by' => null,
    ];

    protected function casts(): array
    {
        return [
            'status' => MembershipStatus::class,
            'is_owner' => 'boolean',
            'is_primary_owner' => 'boolean',
            'joined_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === MembershipStatus::Active && $this->deleted_at === null;
    }
}
