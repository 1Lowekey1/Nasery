<?php

namespace App\Services\Organizations;

use App\Enums\ApprovalStatus;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\OrganizerApprovalRequest;
use App\Models\User;
use App\Services\Notifications\AdminAlerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Organizer approval. organizations.approval_status is authoritative;
 * organizer_approval_requests is its history. Admin decisions go through
 * the sanctioned set_organizer_approval_status() (0021), which re-checks
 * that auth.uid() is an admin, requires reasons, writes the history row and
 * the audit entry, and keeps is_suspended in sync.
 */
class ApprovalWorkflow
{
    /**
     * Admin transitions. Rejected organizations return to "pending" only by
     * the organizer resubmitting.
     *
     * @var array<string, list<ApprovalStatus>>
     */
    private const TRANSITIONS = [
        'pending' => [ApprovalStatus::UnderReview, ApprovalStatus::Approved, ApprovalStatus::Rejected],
        'under_review' => [ApprovalStatus::Approved, ApprovalStatus::Rejected],
        'approved' => [ApprovalStatus::Suspended],
        'suspended' => [ApprovalStatus::Approved],
        'rejected' => [],
    ];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AdminAlerts $adminAlerts,
    ) {}

    /**
     * @return list<ApprovalStatus>
     */
    public function allowedDecisions(Organization $organization): array
    {
        return self::TRANSITIONS[$organization->approval_status->value] ?? [];
    }

    public function decide(Organization $organization, ApprovalStatus $to, ?string $reason): Organization
    {
        // Serialize concurrent decisions: lock the row and re-read its status
        // (system read: RLS would not hand a non-member admin a FOR UPDATE row).
        $this->lock($organization);

        if (! in_array($to, $this->allowedDecisions($organization), true)) {
            throw new ApiException(
                ErrorCode::InvalidStateTransition,
                "An organization that is {$organization->approval_status->value} cannot be set to {$to->value}.",
                409,
                ['allowed' => array_map(fn (ApprovalStatus $s) => $s->value, $this->allowedDecisions($organization))],
            );
        }

        $reason = $reason !== null ? trim($reason) : null;

        if (in_array($to, [ApprovalStatus::Rejected, ApprovalStatus::Suspended], true) && ($reason === null || $reason === '')) {
            throw ValidationException::withMessages(['reason' => 'A reason is required to '.($to === ApprovalStatus::Rejected ? 'reject' : 'suspend').' an organizer.']);
        }

        // Runs as the admin under RLS; the function itself is SECURITY DEFINER.
        DB::select('select set_organizer_approval_status(?, ?::approval_status, ?)', [$organization->id, $to->value, $reason ?: null]);

        return $organization->refresh();
    }

    /**
     * The owner of a rejected organization sends it back for review after
     * fixing their profile. Writes status + history together (system
     * context: approval_status is not app-writable, 0028).
     */
    public function resubmit(User $owner, Organization $organization): Organization
    {
        $this->lock($organization);

        if ($organization->approval_status !== ApprovalStatus::Rejected) {
            throw new ApiException(
                ErrorCode::InvalidStateTransition,
                'Only a rejected application can be resubmitted.',
                409,
            );
        }

        $this->db->system('org.resubmit', function () use ($organization) {
            $organization->forceFill(['approval_status' => ApprovalStatus::Pending])->save();

            OrganizerApprovalRequest::query()->create([
                'organization_id' => $organization->id,
                'status' => ApprovalStatus::Pending,
                'submitted_at' => now(),
            ]);
        });

        $this->audit->record('organization.approval_resubmitted', $organization,
            new: ['approval_status' => ApprovalStatus::Pending->value],
            organizationId: $organization->id, actorUserId: $owner->id);
        $this->adminAlerts->applicationSubmitted($organization->id, $organization->display_name, true);

        return $organization;
    }

    private function lock(Organization $organization): void
    {
        $status = $this->db->system('org.approval_lock', fn () => DB::table('organizations')
            ->where('id', $organization->id)
            ->lockForUpdate()
            ->value('approval_status'));

        $organization->setRawAttributes(array_merge($organization->getAttributes(), ['approval_status' => $status]), true);
    }
}
