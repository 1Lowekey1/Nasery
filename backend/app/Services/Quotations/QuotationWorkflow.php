<?php

namespace App\Services\Quotations;

use App\Enums\InquiryOrgStatus;
use App\Enums\InquiryStatus;
use App\Enums\QuotationStatus;
use App\Exceptions\ApiException;
use App\Models\Inquiry;
use App\Models\Quotation;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Status changes shared by the organizer and client sides. The app role may
 * not write quotation status, history, other parties' invitations or the
 * inquiry row (0032/0007 policies), so each change runs in the logged system
 * context AFTER the caller has authorized the actor and checked state.
 *
 * Lock order is always inquiry → quotation (the client accept path locks
 * the inquiry before calling accept_quotation()), so concurrent actions on
 * one inquiry serialize instead of deadlocking.
 */
class QuotationWorkflow
{
    public function __construct(private readonly DatabaseContext $db) {}

    /** Lock the inquiry row and return its current status. */
    public function lockInquiry(Inquiry $inquiry): InquiryStatus
    {
        $status = $this->db->system('quotation.lock_inquiry', fn () => DB::table('inquiries')
            ->where('id', $inquiry->id)->lockForUpdate()->value('status'));

        $inquiry->setRawAttributes(array_merge($inquiry->getAttributes(), ['status' => $status]), true);

        return InquiryStatus::from($status);
    }

    /** Re-read a quotation's status under a row lock (after lockInquiry). */
    public function lockQuotation(Quotation $quotation): QuotationStatus
    {
        $status = $this->db->system('quotation.lock', fn () => DB::table('quotations')
            ->where('id', $quotation->id)->lockForUpdate()->value('status'));

        $quotation->setRawAttributes(array_merge($quotation->getAttributes(), ['status' => $status]), true);

        return QuotationStatus::from($status);
    }

    /**
     * @param  array<string, mixed>  $columns  extra quotation columns (submitted_at, decided_*)
     */
    public function transition(Quotation $quotation, QuotationStatus $to, ?string $reason = null, array $columns = []): void
    {
        $from = $quotation->status;
        $now = now();

        $this->db->system('quotation.transition', function () use ($quotation, $from, $to, $reason, $columns, $now) {
            DB::table('quotations')->where('id', $quotation->id)
                ->update(['status' => $to->value, 'updated_at' => $now, ...$columns]);

            DB::table('quotation_status_history')->insert([
                'id' => (string) Str::uuid7(),
                'quotation_id' => $quotation->id,
                'from_status' => $from?->value,
                'to_status' => $to->value,
                'actor_user_id' => $this->db->userId(),
                'reason' => $reason,
                'created_at' => $now,
            ]);
        });

        $quotation->setRawAttributes(array_merge($quotation->getAttributes(), ['status' => $to->value, ...$columns]), true);
    }

    public function setInvitationStatus(string $inquiryId, string $organizationId, InquiryOrgStatus $status): void
    {
        $this->db->system('quotation.invitation_status', fn () => DB::table('inquiry_organizations')
            ->where('inquiry_id', $inquiryId)->where('organization_id', $organizationId)
            ->update(['status' => $status->value, 'responded_at' => now(), 'updated_at' => now()]));
    }

    /**
     * Keep the inquiry's bidding state in step with its quotations:
     * submitted ⇄ receiving_bids while it is open.
     */
    public function syncInquiryBidding(Inquiry $inquiry): void
    {
        if (! $inquiry->isOpen()) {
            return;
        }

        $this->db->system('quotation.sync_inquiry', function () use ($inquiry) {
            $hasBids = DB::table('quotations')->where('inquiry_id', $inquiry->id)
                ->where('status', QuotationStatus::Submitted->value)->exists();
            $target = $hasBids ? InquiryStatus::ReceivingBids : InquiryStatus::Submitted;

            if ($inquiry->status !== $target) {
                DB::table('inquiries')->where('id', $inquiry->id)->update(['status' => $target->value, 'updated_at' => now()]);
                $inquiry->setRawAttributes(array_merge($inquiry->getAttributes(), ['status' => $target->value]), true);
            }
        });
    }

    /**
     * Cancel every active (draft/submitted) quotation of an inquiry that is
     * being closed (client cancel, auto-close, or another quote accepted).
     */
    public function cancelActive(Inquiry $inquiry, string $reason): int
    {
        $active = $this->db->system('quotation.active_for_inquiry', fn () => Quotation::query()
            ->where('inquiry_id', $inquiry->id)
            ->whereIn('status', array_map(fn ($s) => $s->value, Quotation::ACTIVE_STATUSES))
            ->orderBy('id')->lockForUpdate()->get());

        foreach ($active as $quotation) {
            $this->transition($quotation, QuotationStatus::Cancelled, $reason);
        }

        return $active->count();
    }

    public static function notFound(): ApiException
    {
        return new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
    }
}
