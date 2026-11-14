<?php

namespace App\Http\Resources;

use App\Models\OrganizerApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of an organization's approval history.
 *
 * @mixin OrganizerApprovalRequest
 */
class ApprovalHistoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status->value,
            'decision_reason' => $this->decision_reason,
            // A reviewer's row in a review status with a message is a note, not a decision.
            'kind' => match (true) {
                $this->reviewed_by === null => 'submission',
                in_array($this->status->value, ['pending', 'under_review'], true) && filled($this->decision_reason) => 'note',
                default => 'decision',
            },
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'decided_at' => $this->decided_at?->toIso8601String(),
        ];
    }
}
