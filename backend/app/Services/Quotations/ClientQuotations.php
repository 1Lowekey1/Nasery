<?php

namespace App\Services\Quotations;

use App\Enums\InquiryOrgStatus;
use App\Enums\QuotationStatus;
use App\Exceptions\ApiException;
use App\Models\Inquiry;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\Notifications\Alerts;

/**
 * The client side of quotations: compare, accept, decline. Reads run under
 * RLS (0032: non-draft quotations of the client's own inquiries and their
 * cost lines). Acceptance goes through accept_quotation(), which accepts the
 * chosen quotation, rejects every competing one and records the history in
 * one transaction; it does not create an event (B6 converts it).
 */
class ClientQuotations
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly QuotationWorkflow $workflow,
        private readonly Alerts $alerts,
    ) {}

    /**
     * One entry per organizer: its current quotation (the latest one sent),
     * with how many earlier versions it replaced.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forInquiry(Inquiry $inquiry): Collection
    {
        $all = Quotation::query()->where('inquiry_id', $inquiry->id)
            ->orderByDesc('submitted_at')->orderByDesc('created_at')->get();

        if ($all->isEmpty()) {
            return collect();
        }

        $current = $all->groupBy('organization_id')->map(fn (Collection $versions) => [
            'quotation' => $versions->first(),
            'earlier_versions' => $versions->count() - 1,
        ]);

        $items = QuotationItem::query()->whereIn('quotation_id', $current->pluck('quotation.id'))
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('quotation_id');

        // Invited organizers are listed ones; names come from the public view.
        $organizers = $this->db->system('quotation.organizer_names', fn () => DB::table('public_organization_profiles')
            ->whereIn('organization_id', $current->keys())
            ->get(['organization_id', 'display_name', 'slug', 'contact_email', 'contact_phone'])->keyBy('organization_id'));

        return $current->map(function (array $entry, string $orgId) use ($items, $organizers, $inquiry) {
            $q = $entry['quotation'];
            $org = $organizers[$orgId] ?? null;

            $content = OrganizerQuotations::content($q, $items[$q->id] ?? collect());
            $content['items'] = $content['items']->map(fn (array $i) => [...$i, 'description' => OrganizerQuotations::forClient($i['description'])])->values();

            return [
                ...$content,
                'earlier_versions' => $entry['earlier_versions'],
                // 0051: sent before the client's latest change to the request.
                'before_changes' => OrganizerQuotations::beforeChanges($q, $inquiry),
                'organizer' => [
                    'id' => $orgId,
                    'display_name' => $org->display_name ?? 'Organizer no longer listed',
                    'slug' => $org->slug ?? null,
                    'contact_email' => $org->contact_email ?? null,
                    'contact_phone' => $org->contact_phone ?? null,
                ],
            ];
        })
            // Offers still on the table first, cheapest first; then decided ones.
            ->sortBy(fn (array $q) => [in_array($q['status'], ['submitted', 'accepted'], true) ? 0 : 1, (float) $q['total_amount']])
            ->values();
    }

    public function accept(Quotation $quotation): void
    {
        $inquiry = $this->lockOpenInquiry($quotation);

        if ($this->workflow->lockQuotation($quotation) !== QuotationStatus::Submitted) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This quotation is no longer available to accept.', 409);
        }

        // Accepts, rejects competing submitted quotations, updates the inquiry
        // and every invitation, writes history + an audit row (0025).
        DB::select('select accept_quotation(?)', [$quotation->id]);

        // Organizers still drafting can no longer send theirs.
        $inquiry->setRawAttributes(array_merge($inquiry->getAttributes(), ['status' => 'accepted']), true);
        $this->workflow->cancelActive($inquiry, 'Client accepted another quotation');
        $this->alerts->quotationDecided($quotation, accepted: true);
    }

    public function decline(Quotation $quotation, string $reason): void
    {
        $inquiry = $this->lockOpenInquiry($quotation);

        if ($this->workflow->lockQuotation($quotation) !== QuotationStatus::Submitted) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This quotation is no longer open.', 409);
        }

        $this->workflow->transition($quotation, QuotationStatus::Rejected, $reason, [
            'decided_at' => now(),
            'decided_by' => $this->db->userId(),
        ]);
        $this->workflow->setInvitationStatus($inquiry->id, $quotation->organization_id, InquiryOrgStatus::Rejected);
        $this->workflow->syncInquiryBidding($inquiry);

        $this->audit->record('quotation.declined', $quotation, organizationId: $quotation->organization_id, reason: $reason);
        $this->alerts->quotationDecided($quotation, accepted: false, reason: $reason);
    }

    private function lockOpenInquiry(Quotation $quotation): Inquiry
    {
        $inquiry = Inquiry::query()->find($quotation->inquiry_id) ?? throw QuotationWorkflow::notFound();
        $this->workflow->lockInquiry($inquiry);

        if (! $inquiry->isOpen()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This inquiry is no longer open.', 409);
        }

        return $inquiry;
    }
}
