<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;

/**
 * public.organizer_approval_requests (0018): append-only workflow history.
 * organizations.approval_status stays the authoritative current state; both
 * are always written together (set_organizer_approval_status() for admin
 * decisions, the onboarding/resubmission actions for organizer submissions).
 */
#[Fillable(['organization_id', 'status', 'reviewed_by', 'decision_reason', 'submitted_at', 'decided_at'])]
class OrganizerApprovalRequest extends Model
{
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }
}
