<?php

namespace App\Models;

use App\Enums\InquiryOrgStatus;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * public.inquiry_organizations (0007): an organizer invited to an inquiry
 * (directly by the client, or by open-posting matching).
 *
 * Rows are created only by the server's controlled path (0030 revoked
 * INSERT from nasery_app); members may update status / decline_reason /
 * responded_at on their own invitations. accept_quotation() (B5) sets the
 * final accepted / rejected outcome.
 */
#[Table('inquiry_organizations')]
class InquiryInvitation extends Model
{
    protected $guarded = ['id', 'inquiry_id', 'organization_id'];

    protected function casts(): array
    {
        return [
            'status' => InquiryOrgStatus::class,
            'responded_at' => 'datetime',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function isAwaitingResponse(): bool
    {
        return in_array($this->status, [InquiryOrgStatus::Pending, InquiryOrgStatus::Viewed], true);
    }
}
