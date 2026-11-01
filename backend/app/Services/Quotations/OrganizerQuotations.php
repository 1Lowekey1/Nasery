<?php

namespace App\Services\Quotations;

use App\Enums\InquiryOrgStatus;
use App\Enums\QuotationStatus;
use App\Exceptions\ApiException;
use App\Models\Inquiry;
use App\Models\InquiryInvitation;
use App\Models\Organization;
use App\Models\OrganizationService;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Services\Notifications\Alerts;

/**
 * The organizer side of quotations ("My Offers"). Content (quotation row and
 * cost lines) is written under RLS as the member; status changes go through
 * QuotationWorkflow. Clients stay anonymous (first name + last initial)
 * until they accept this organizer's quotation; then their contact details
 * are shown so the parties can agree terms outside NaSeRy (paper).
 */
class OrganizerQuotations
{
    /** Invitation states from which the organizer may (re)quote. */
    private const QUOTABLE = [InquiryOrgStatus::Pending, InquiryOrgStatus::Viewed, InquiryOrgStatus::Quoted, InquiryOrgStatus::Withdrawn];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly QuotationWorkflow $workflow,
        private readonly Alerts $alerts,
    ) {}

    /**
     * @param  list<string>|null  $statuses
     */
    public function list(Organization $organization, ?array $statuses, int $page): LengthAwarePaginator
    {
        $paginator = Quotation::query()
            ->where('organization_id', $organization->id)
            ->when($statuses, fn ($q) => $q->whereIn('status', $statuses))
            ->orderByRaw("case status when 'draft' then 0 when 'submitted' then 1 else 2 end")
            ->orderByDesc('updated_at')
            ->paginate(perPage: 15, page: $page);

        $presented = $this->presentMany($paginator->getCollection());
        $paginator->setCollection($presented);

        return $paginator;
    }

    /**
     * @return array<string, mixed>
     */
    public function show(Quotation $quotation): array
    {
        return $this->presentMany(collect([$quotation]))->first();
    }

    /**
     * The organizer's current (draft or submitted) quotation for an inquiry,
     * if any.
     */
    public function activeFor(Organization $organization, string $inquiryId): ?Quotation
    {
        return Quotation::query()
            ->where('organization_id', $organization->id)
            ->where('inquiry_id', $inquiryId)
            ->whereIn('status', array_map(fn ($s) => $s->value, Quotation::ACTIVE_STATUSES))
            ->first();
    }

    /**
     * @param  array{package_name?: ?string, proposal?: ?string, terms?: ?string, items: list<array<string, mixed>>}  $data
     */
    public function create(Organization $organization, Inquiry $inquiry, array $data, bool $submit): Quotation
    {
        $invitation = $this->invitation($organization, $inquiry);
        $this->assertInquiryOpen($inquiry);

        if (! in_array($invitation->status, self::QUOTABLE, true)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'You declined this inquiry or it has been decided.', 409);
        }

        if ($this->activeFor($organization, $inquiry->id) !== null) {
            throw new ApiException(ErrorCode::Conflict, 'You already have a quotation for this inquiry. Edit or revise it instead.', 409);
        }

        $quotation = $this->write(new Quotation, $organization, $inquiry->id, $data);
        $this->audit->record('quotation.created', $quotation, new: ['total_amount' => $quotation->total_amount], organizationId: $organization->id);

        if ($submit) {
            $this->submitLocked($organization, $inquiry, $quotation, 'submitted');
            $this->alerts->quotationReceived($quotation);
        }

        return $quotation->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(Organization $organization, Quotation $quotation, array $data): Quotation
    {
        $inquiry = $this->inquiry($quotation);
        $this->workflow->lockInquiry($inquiry);
        $this->workflow->lockQuotation($quotation);

        if (! $quotation->isDraft()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a draft can be edited. Revise a sent quotation instead.', 409);
        }

        $this->write($quotation, $organization, $quotation->inquiry_id, $data);
        $this->audit->record('quotation.updated', $quotation, new: ['total_amount' => $quotation->total_amount], organizationId: $organization->id);

        return $quotation->refresh();
    }

    public function submit(Organization $organization, Quotation $quotation): Quotation
    {
        $inquiry = $this->inquiry($quotation);
        $this->submitLocked($organization, $inquiry, $quotation, 'submitted');
        $this->alerts->quotationReceived($quotation);

        return $quotation->refresh();
    }

    /**
     * "Edit Offer" on a sent quotation: the current one is withdrawn and the
     * revised content is sent as a new quotation in the same transaction, so
     * the client always sees exactly one current offer and earlier versions
     * stay on record.
     *
     * @param  array<string, mixed>  $data
     */
    public function revise(Organization $organization, Quotation $quotation, array $data): Quotation
    {
        $inquiry = $this->inquiry($quotation);
        $this->assertInquiryOpen($inquiry, lock: true);

        if ($this->workflow->lockQuotation($quotation) !== QuotationStatus::Submitted) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a sent quotation can be revised.', 409);
        }

        $this->workflow->transition($quotation, QuotationStatus::Withdrawn, 'Replaced by a revised quotation');

        $revision = $this->write(new Quotation, $organization, $inquiry->id, $data);
        $this->workflow->transition($revision, QuotationStatus::Submitted, "Revision of quotation {$quotation->id}", ['submitted_at' => now()]);

        $this->audit->record('quotation.revised', $revision, old: ['quotation_id' => $quotation->id, 'total_amount' => $quotation->total_amount],
            new: ['total_amount' => $revision->total_amount], organizationId: $organization->id);
        $this->alerts->quotationReceived($revision, revised: true);

        return $revision->refresh();
    }

    public function withdraw(Organization $organization, Quotation $quotation, string $reason): Quotation
    {
        $inquiry = $this->inquiry($quotation);
        $this->workflow->lockInquiry($inquiry);

        if ($this->workflow->lockQuotation($quotation) !== QuotationStatus::Submitted) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a sent quotation can be withdrawn.', 409);
        }

        $this->workflow->transition($quotation, QuotationStatus::Withdrawn, $reason);
        $this->workflow->setInvitationStatus($inquiry->id, $organization->id, InquiryOrgStatus::Withdrawn);
        $this->workflow->syncInquiryBidding($inquiry);

        $this->audit->record('quotation.withdrawn', $quotation, organizationId: $organization->id, reason: $reason);

        return $quotation->refresh();
    }

    public function deleteDraft(Organization $organization, Quotation $quotation): void
    {
        if ($this->workflow->lockQuotation($quotation) !== QuotationStatus::Draft) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only drafts can be deleted; withdraw a sent quotation instead.', 409);
        }

        // Drafts were never visible to the client and have no history.
        QuotationItem::query()->where('quotation_id', $quotation->id)->delete();
        $quotation->delete();

        $this->audit->record('quotation.draft_deleted', $quotation, organizationId: $organization->id);
    }

    private function submitLocked(Organization $organization, Inquiry $inquiry, Quotation $quotation, string $reason): void
    {
        $this->assertInquiryOpen($inquiry, lock: true);

        if ($this->workflow->lockQuotation($quotation) !== QuotationStatus::Draft) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This quotation has already been sent.', 409);
        }

        if (! in_array($this->invitation($organization, $inquiry)->status, self::QUOTABLE, true)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'You declined this inquiry or it has been decided.', 409);
        }

        $this->workflow->transition($quotation, QuotationStatus::Submitted, $reason, ['submitted_at' => now()]);
        $this->workflow->setInvitationStatus($inquiry->id, $organization->id, InquiryOrgStatus::Quoted);
        $this->workflow->syncInquiryBidding($inquiry);

        $this->audit->record('quotation.submitted', $quotation, new: ['total_amount' => $quotation->total_amount], organizationId: $organization->id);
    }

    /**
     * Save content and replace the cost lines; the total is always the sum of
     * the lines (computed in cents, so no float drift).
     *
     * @param  array<string, mixed>  $data
     */
    private function write(Quotation $quotation, Organization $organization, string $inquiryId, array $data): Quotation
    {
        $lines = $this->lines($organization, $data['items']);
        $total = array_sum(array_column($lines, 'subtotal_cents'));

        if ($total > 999_999_999_999) {
            throw ValidationException::withMessages(['items' => 'The quotation total is too large.']);
        }

        $quotation->fill([
            'package_name' => $data['package_name'] ?? null,
            'proposal' => $data['proposal'] ?? null,
            'terms' => $data['terms'] ?? null,
            'total_amount' => self::money($total),
        ]);

        $created = ! $quotation->exists;

        if ($created) {
            $quotation->forceFill(['inquiry_id' => $inquiryId, 'organization_id' => $organization->id]);
        }

        $quotation->save();

        if ($created) {
            $quotation->refresh(); // load DB defaults (status 'draft', timestamps)
        }

        QuotationItem::query()->where('quotation_id', $quotation->id)->delete();

        foreach ($lines as $line) {
            QuotationItem::query()->forceCreate([
                'quotation_id' => $quotation->id,
                'organization_service_id' => $line['organization_service_id'],
                'description' => $line['description'],
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'],
                'subtotal' => self::money($line['subtotal_cents']),
            ]);
        }

        return $quotation;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{organization_service_id: ?string, description: string, quantity: string, unit_price: string, subtotal_cents: int}>
     */
    private function lines(Organization $organization, array $items): array
    {
        $serviceIds = collect($items)->pluck('organization_service_id')->filter()->unique()->values();

        if ($serviceIds->isNotEmpty()) {
            $own = OrganizationService::query()->where('organization_id', $organization->id)->whereIn('id', $serviceIds)->count();

            if ($own !== $serviceIds->count()) {
                throw ValidationException::withMessages(['items' => 'A line refers to a service that is not yours.']);
            }
        }

        return array_map(function (array $item) {
            $qty = self::cents($item['quantity']);
            $price = self::cents($item['unit_price']);

            return [
                'organization_service_id' => $item['organization_service_id'] ?? null,
                'description' => trim($item['description']),
                'quantity' => self::money($qty),
                'unit_price' => self::money($price),
                // qty × price in cents², rounded half-up back to cents.
                'subtotal_cents' => intdiv($qty * $price + 50, 100),
            ];
        }, array_values($items));
    }

    private static function cents(int|float|string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    private static function money(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    private function invitation(Organization $organization, Inquiry $inquiry): InquiryInvitation
    {
        return InquiryInvitation::query()
            ->where('organization_id', $organization->id)
            ->where('inquiry_id', $inquiry->id)
            ->first() ?? throw QuotationWorkflow::notFound();
    }

    private function inquiry(Quotation $quotation): Inquiry
    {
        return Inquiry::query()->find($quotation->inquiry_id) ?? throw QuotationWorkflow::notFound();
    }

    private function assertInquiryOpen(Inquiry $inquiry, bool $lock = false): void
    {
        if ($lock) {
            $this->workflow->lockInquiry($inquiry);
        }

        if (! $inquiry->isOpen()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This inquiry is no longer accepting quotations.', 409);
        }
    }

    /**
     * @param  Collection<int, Quotation>  $quotations
     * @return Collection<int, array<string, mixed>>
     */
    private function presentMany(Collection $quotations): Collection
    {
        if ($quotations->isEmpty()) {
            return collect();
        }

        $items = QuotationItem::query()->whereIn('quotation_id', $quotations->pluck('id'))
            ->orderBy('created_at')->orderBy('id')->get()->groupBy('quotation_id');
        $inquiries = Inquiry::query()->whereIn('id', $quotations->pluck('inquiry_id')->unique())->get()->keyBy('id');
        $types = DB::table('event_types')->whereIn('id', $inquiries->pluck('event_type_id')->filter()->unique())->pluck('name', 'id');
        // The event created from a won quotation (B6), if any.
        $events = DB::table('events')->whereIn('accepted_quotation_id', $quotations->pluck('id'))->whereNull('deleted_at')
            ->pluck('id', 'accepted_quotation_id');

        // Organizers can't read users rows. Full contact only for clients
        // who accepted this organizer's quotation.
        $acceptedClients = $quotations->where('status', QuotationStatus::Accepted)
            ->map(fn (Quotation $q) => $inquiries[$q->inquiry_id]?->client_user_id)->filter()->unique();
        $clients = $this->db->system('quotation.client_names', fn () => DB::table('users')
            ->whereIn('id', $inquiries->pluck('client_user_id')->unique())
            ->get(['id', 'first_name', 'last_name', 'email', 'phone'])->keyBy('id'));

        return $quotations->map(function (Quotation $q) use ($items, $inquiries, $types, $clients, $acceptedClients, $events) {
            $inquiry = $inquiries[$q->inquiry_id] ?? null;
            $client = $inquiry ? ($clients[$inquiry->client_user_id] ?? null) : null;
            $revealed = $client && $acceptedClients->contains($client->id);

            return [
                ...self::content($q, $items[$q->id] ?? collect()),
                'event_id' => $events[$q->id] ?? null,
                'before_changes' => self::beforeChanges($q, $inquiry),
                'inquiry' => $inquiry ? [
                    'id' => $inquiry->id,
                    'status' => $inquiry->status->value,
                    'event_name' => $inquiry->eventName(),
                    'event_type_id' => $inquiry->event_type_id,
                    'event_type' => $types[$inquiry->event_type_id] ?? null,
                    'requested_date' => $inquiry->requested_date?->toDateString(),
                    'guest_count' => $inquiry->guest_count,
                    'budget_min' => $inquiry->budget_min,
                    'budget_max' => $inquiry->budget_max,
                    'location_text' => $inquiry->location_text,
                    'revised_at' => $inquiry->revised_at?->toIso8601String(),
                ] : null,
                'client' => $client ? ($revealed
                    ? ['name' => trim($client->first_name.' '.$client->last_name), 'email' => $client->email, 'phone' => $client->phone]
                    : ['name' => trim($client->first_name.' '.mb_substr((string) $client->last_name, 0, 1).'.'), 'email' => null, 'phone' => null])
                    : null,
            ];
        })->values();
    }

    /** 0051: a sent quotation the client's later change may have outdated. */
    public static function beforeChanges(Quotation $q, ?Inquiry $inquiry): bool
    {
        // Whole seconds: submitted_at is written without fractions (status transition).
        return $inquiry?->revised_at !== null && $q->status === QuotationStatus::Submitted
            && $q->submitted_at !== null && $q->submitted_at->lt($inquiry->revised_at->copy()->startOfSecond());
    }

    /**
     * Quotation fields + cost lines, shared with the client view.
     *
     * @param  Collection<int, QuotationItem>  $items
     * @return array<string, mixed>
     */
    public static function content(Quotation $q, Collection $items): array
    {
        return [
            'id' => $q->id,
            'inquiry_id' => $q->inquiry_id,
            'organization_id' => $q->organization_id,
            'status' => $q->status->value,
            'package_name' => $q->package_name,
            'proposal' => $q->proposal,
            'terms' => $q->terms,
            'total_amount' => $q->total_amount,
            'currency' => $q->currency,
            'submitted_at' => $q->submitted_at?->toIso8601String(),
            'decided_at' => $q->decided_at?->toIso8601String(),
            'updated_at' => $q->updated_at?->toIso8601String(),
            'items' => $items->map(fn (QuotationItem $i) => [
                'id' => $i->id,
                'organization_service_id' => $i->organization_service_id,
                'description' => $i->description,
                'quantity' => $i->quantity,
                'unit_price' => $i->unit_price,
                'subtotal' => $i->subtotal,
            ])->values(),
        ];
    }
}
