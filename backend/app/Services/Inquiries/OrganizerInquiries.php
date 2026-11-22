<?php

namespace App\Services\Inquiries;

use App\Enums\InquiryOrgStatus;
use App\Exceptions\ApiException;
use App\Models\Inquiry;
use App\Models\InquiryInvitation;
use App\Models\Organization;
use App\Models\Quotation;
use App\Services\Quotations\OrganizerQuotations;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The organizer side of inquiries ("Matching inquiries"). Everything reads
 * and writes under RLS as the member: invitations of the member's own
 * organization (inquiry_orgs_org), the invited inquiries (inquiries_org_read)
 * and their requested services. Only the client's display name comes from a
 * system read, because organizers can't see users rows; clients stay
 * anonymous (first name + last initial) until they accept this organizer's
 * quotation (see OrganizerQuotations).
 */
class OrganizerInquiries
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>|null  $statuses
     */
    public function list(Organization $organization, ?array $statuses, int $page): LengthAwarePaginator
    {
        $paginator = InquiryInvitation::query()
            ->where('organization_id', $organization->id)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->whereHas('inquiry')
            ->with('inquiry')
            ->orderByRaw("case status when 'pending' then 0 when 'viewed' then 1 else 2 end")
            ->orderByDesc('created_at')
            ->paginate(perPage: 15, page: $page);

        $inquiries = $paginator->getCollection()->pluck('inquiry');
        $extras = $this->extras($inquiries, $organization);

        // The redesigned inquiry cards show everything at once (no "open" step), so the list carries the details too.
        $paginator->getCollection()->transform(fn (InquiryInvitation $i) => $this->present($i, $extras, detailed: true));

        return $paginator;
    }

    /**
     * Opening an invitation marks it viewed (the client sees who looked).
     *
     * @return array<string, mixed>
     */
    public function show(Organization $organization, Inquiry $inquiry): array
    {
        $invitation = $this->invitation($organization, $inquiry);

        if ($invitation->status === InquiryOrgStatus::Pending && $inquiry->isOpen()) {
            $invitation->update(['status' => InquiryOrgStatus::Viewed]);
        }

        $invitation->setRelation('inquiry', $inquiry);

        return [...$this->present($invitation, $this->extras(collect([$inquiry]), $organization), detailed: true),
            // Field test (Nov 2026): the booked organizer adds to the mood board until the event is over.
            'mood_board_editable' => app(MoodBoards::class)->bookedBy($inquiry, $organization)];
    }

    /**
     * @return array<string, mixed>
     */
    public function decline(Organization $organization, Inquiry $inquiry, string $reason): array
    {
        $invitation = $this->invitation($organization, $inquiry, lock: true);

        if (! $invitation->isAwaitingResponse()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'You have already responded to this inquiry.', 409);
        }

        if (! $inquiry->isOpen()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This inquiry is no longer open.', 409);
        }

        $invitation->update([
            'status' => InquiryOrgStatus::Declined,
            'decline_reason' => $reason,
            'responded_at' => now(),
        ]);

        $this->audit->record('inquiry.declined', $invitation, new: ['inquiry_id' => $inquiry->id], organizationId: $organization->id, reason: $reason);

        $invitation->setRelation('inquiry', $inquiry);

        return $this->present($invitation, $this->extras(collect([$inquiry]), $organization), detailed: true);
    }

    private function invitation(Organization $organization, Inquiry $inquiry, bool $lock = false): InquiryInvitation
    {
        $invitation = InquiryInvitation::query()
            ->where('organization_id', $organization->id)
            ->where('inquiry_id', $inquiry->id)
            ->when($lock, fn ($q) => $q->lockForUpdate())
            ->first();

        if ($invitation === null) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        return $invitation;
    }

    /**
     * Event type names, requested services, client display names and this
     * organization's latest quotation for a set of inquiries.
     *
     * @param  Collection<int, Inquiry>  $inquiries
     * @return array{types: Collection, services: Collection, clients: Collection, quotations: Collection}
     */
    private function extras(Collection $inquiries, Organization $organization): array
    {
        $ids = $inquiries->pluck('id')->filter()->values();
        $requested = $ids->isEmpty() ? collect() : DB::table('inquiry_services')
            ->whereIn('inquiry_id', $ids)->orderBy('created_at')
            ->get(['inquiry_id', 'service_id', 'custom_service_name', 'notes']);

        // The platform catalog has no RLS grant for the app role: system read.
        $catalogIds = $requested->pluck('service_id')->filter()->unique()->values();
        $catalog = $catalogIds->isEmpty() ? collect() : $this->db->system('services.catalog', fn () => DB::table('services')->whereIn('id', $catalogIds)->pluck('name', 'id'));

        $quotations = $ids->isEmpty() ? collect() : Quotation::query()
            ->where('organization_id', $organization->id)->whereIn('inquiry_id', $ids)
            ->orderByDesc('created_at')->get(['id', 'inquiry_id', 'status', 'total_amount', 'submitted_at'])
            ->unique('inquiry_id')->keyBy('inquiry_id');

        // 0062: does this organizer add VAT to offers for each request's event type?
        $profile = DB::table('organization_profiles')->where('organization_id', $organization->id)->first(['estimate_tax_percent', 'vat_exempt_event_types']);
        $chargesVat = app(\App\Services\Admin\PlatformSettings::class)->vatFor($profile?->estimate_tax_percent) !== null;
        $vatFree = json_decode((string) ($profile->vat_exempt_event_types ?? '[]'), true) ?: [];

        return [
            'vat' => ['charges' => $chargesVat, 'exempt' => $vatFree],
            'quotations' => $quotations,
            'types' => DB::table('event_types')->whereIn('id', $inquiries->pluck('event_type_id')->filter()->unique())->pluck('name', 'id'),
            'services' => $requested->map(function ($s) use ($catalog) {
                $s->custom_service_name ??= $catalog[$s->service_id] ?? null;

                return $s;
            })->groupBy('inquiry_id'),
            'clients' => $this->db->system('inquiry.client_names', fn () => DB::table('users')
                ->whereIn('id', $inquiries->pluck('client_user_id')->unique())
                ->get(['id', 'first_name', 'last_name'])
                ->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name.' '.mb_substr((string) $u->last_name, 0, 1).'.')])),
        ];
    }

    /**
     * @param  array{types: Collection, services: Collection, clients: Collection, quotations: Collection}  $extras
     * @return array<string, mixed>
     */
    private function present(InquiryInvitation $invitation, array $extras, bool $detailed): array
    {
        $inquiry = $invitation->inquiry;

        return [
            'inquiry_id' => $inquiry->id,
            'invitation_status' => $invitation->status->value,
            'decline_reason' => $invitation->decline_reason,
            'invited_at' => $invitation->created_at?->toIso8601String(),
            'responded_at' => $invitation->responded_at?->toIso8601String(),
            'inquiry_status' => $inquiry->status->value,
            'is_open' => $inquiry->isOpen(),
            // 0051: when the client last changed the request after sending it.
            'revised_at' => $inquiry->revised_at?->toIso8601String(),
            'via' => $inquiry->isOpenPosting() ? 'open_posting' : 'direct',
            'client' => $extras['clients'][$inquiry->client_user_id] ?? 'Client',
            // Shown as the card title ("Marlo & Rena Wedding", posted by the client).
            'event_name' => $inquiry->eventName(),
            'event_type' => $extras['types'][$inquiry->event_type_id] ?? null,
            'requested_date' => $inquiry->requested_date?->toDateString(),
            'guest_count' => $inquiry->guest_count,
            'budget_min' => $inquiry->budget_min,
            'budget_max' => $inquiry->budget_max,
            'location_text' => $inquiry->location_text,
            'services' => collect($extras['services'][$inquiry->id] ?? [])->map(fn ($s) => [
                'name' => $s->custom_service_name,
                'notes' => $detailed ? $s->notes : null,
            ])->values(),
            'additional_requirements' => $detailed ? $inquiry->additional_requirements : null,
            // 0062: whether NaSeRy adds VAT to this organizer's offer for this request.
            'vat_applies' => ($extras['vat']['charges'] ?? false) && ! in_array($inquiry->event_type_id, $extras['vat']['exempt'] ?? [], true),
            'mood_board' => $detailed ? \App\Models\Inquiry::moodBoard($inquiry->mood_board) : [],
            'quotation' => ($q = $extras['quotations'][$inquiry->id] ?? null)
                ? ['id' => $q->id, 'status' => $q->status->value, 'total_amount' => $q->total_amount,
                    'before_changes' => OrganizerQuotations::beforeChanges($q, $inquiry)]
                : null,
        ];
    }
}
