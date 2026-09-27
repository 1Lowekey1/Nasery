<?php

namespace App\Services\Events;

use App\Enums\EventStatus;
use App\Enums\InquiryStatus;
use App\Enums\QuotationStatus;
use App\Enums\SessionStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\EventSession;
use App\Models\Inquiry;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Quotation;
use App\Services\Billing\Entitlements;
use App\Services\Notifications\Alerts;
use App\Services\Preparation\ProgressTracker;
use App\Services\Quotations\QuotationWorkflow;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Event core (B6). Events and sessions are written under RLS as the member;
 * the inquiry link and the inquiry's converted_to_event state are written in
 * the system context after convert() has verified the quotation was accepted
 * for this organization (0034 revokes those columns from the app role).
 *
 * Status transitions (permission in brackets, checked by the controller):
 *   publish    draft → published          [event.publish] needs ≥1 session
 *   unpublish  published → draft          [event.publish]
 *   start      published → ongoing        [event.update]
 *   complete   published|ongoing → completed [event.complete] after the first session began
 *   cancel     draft|published|ongoing → cancelled [event.cancel] with a reason
 */
class EventManager
{
    public const TRANSITIONS = [
        'publish' => [[EventStatus::Draft], EventStatus::Published],
        'unpublish' => [[EventStatus::Published], EventStatus::Draft],
        'start' => [[EventStatus::Published], EventStatus::Ongoing],
        'complete' => [[EventStatus::Published, EventStatus::Ongoing], EventStatus::Completed],
        'cancel' => [[EventStatus::Draft, EventStatus::Published, EventStatus::Ongoing], EventStatus::Cancelled],
    ];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly QuotationWorkflow $quotations,
        private readonly ProgressTracker $progress,
        private readonly Alerts $alerts,
        private readonly Entitlements $entitlements,
    ) {}

    /**
     * @param  array<string, mixed>  $data  event fields + optional sessions[]
     */
    public function create(Organization $organization, OrganizationMember $creator, array $data, bool $refreshProgress = true): Event
    {
        // B18 plan limits: block new events past the plan; existing ones are never touched.
        $this->entitlements->assertRoom($organization, 'active_event_limit');
        $this->entitlements->assertRoom($organization, 'total_event_limit');
        $this->assertEventType($organization, $data['event_type_id'] ?? null);

        $event = new Event(collect($data)->except('sessions')->all());
        $event->forceFill(['organization_id' => $organization->id, 'created_by_member_id' => $creator->id])->save();
        $event->refresh();

        foreach ($data['sessions'] ?? [] as $session) {
            $this->writeSession($event, new EventSession, $session);
        }

        $this->audit->record('event.created', $event, new: collect($data)->except('sessions')->all(), organizationId: $organization->id);

        if ($refreshProgress) {
            $this->progress->refresh($event);
        }

        return $event;
    }

    /**
     * Turn an inquiry won with $quotation into a draft event (pack: acceptance
     * does not create the event; the winning organizer does, once).
     *
     * @param  array<string, mixed>  $data
     */
    public function convert(Organization $organization, OrganizationMember $creator, Quotation $quotation, array $data): Event
    {
        $inquiry = Inquiry::query()->find($quotation->inquiry_id) ?? throw QuotationWorkflow::notFound();
        $status = $this->quotations->lockInquiry($inquiry);

        if ($quotation->organization_id !== $organization->id || $quotation->status !== QuotationStatus::Accepted) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only a quotation the client accepted can become an event.', 409);
        }

        if ($status === InquiryStatus::ConvertedToEvent) {
            throw new ApiException(ErrorCode::Conflict, 'An event has already been created for this inquiry.', 409);
        }

        if ($status !== InquiryStatus::Accepted) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This inquiry is no longer accepted.', 409);
        }

        $event = $this->create($organization, $creator, $data, refreshProgress: false);

        $this->db->system('event.convert_inquiry', function () use ($event, $inquiry, $quotation) {
            DB::table('events')->where('id', $event->id)
                ->update(['inquiry_id' => $inquiry->id, 'accepted_quotation_id' => $quotation->id, 'updated_at' => now()]);
            DB::table('inquiries')->where('id', $inquiry->id)
                ->update(['status' => InquiryStatus::ConvertedToEvent->value, 'updated_at' => now()]);
        });

        $this->audit->record('event.converted_from_inquiry', $event, new: ['inquiry_id' => $inquiry->id, 'quotation_id' => $quotation->id], organizationId: $organization->id);
        $this->progress->refresh($event->refresh());
        $this->alerts->eventCreated($event);

        return $event;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, array $data): Event
    {
        $this->lock($event);
        $this->assertEditable($event);

        if (array_key_exists('event_type_id', $data)) {
            $this->assertEventType($event->organization_id, $data['event_type_id']);
        }

        $old = $event->only(array_keys($data));
        $event->fill($data)->save();

        // Session instants are UTC; keep their timezone label in step.
        if ($event->wasChanged('timezone')) {
            $event->sessions()->update(['timezone' => $event->timezone, 'updated_at' => now()]);
        }

        $this->audit->record('event.updated', $event, old: $old, new: $data, organizationId: $event->organization_id);

        return $event;
    }

    public function transition(Event $event, string $action, ?string $reason = null): Event
    {
        [$from, $to] = self::TRANSITIONS[$action];
        $this->lock($event);

        if (! in_array($event->status, $from, true)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, "An event that is {$event->status->value} can't be {$this->past($action)}.", 409);
        }

        $sessions = $event->sessions()->where('status', '!=', SessionStatus::Cancelled->value)->get();

        if ($action === 'publish' && $sessions->isEmpty()) {
            throw ValidationException::withMessages(['sessions' => 'Add the event date and time before publishing.']);
        }

        // B9: an event with issued tickets can't go back to draft (holders would lose their event).
        if ($action === 'unpublish' && DB::table('tickets')->where('event_id', $event->id)->whereIn('status', ['valid', 'used'])->exists()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Tickets have been issued for this event, so it can no longer go back to draft. Cancel it instead.', 409);
        }

        if ($action === 'complete' && ($sessions->isEmpty() || $sessions->first()->starts_at->isFuture())) {
            throw new ApiException(ErrorCode::InvalidStateTransition, "An event can't be completed before it has started.", 409);
        }

        $old = $event->status;
        $columns = ['status' => $to];

        if ($action === 'cancel') {
            $columns += ['cancelled_reason' => $reason, 'cancelled_at' => now()];
            $event->sessions()->whereIn('status', [SessionStatus::Upcoming->value, SessionStatus::Ongoing->value])
                ->update(['status' => SessionStatus::Cancelled->value, 'updated_at' => now()]);
            $voided = $this->cancelTickets($event, $reason);
        }

        $event->forceFill($columns)->save();
        $this->audit->record("event.{$this->past($action)}", $event, old: ['status' => $old->value], new: ['status' => $to->value],
            organizationId: $event->organization_id, reason: $reason);
        $this->progress->refresh($event);

        if ($action === 'cancel') {
            $this->alerts->eventCancelled($event, $voided ?? [], $reason);
        }
        if ($action === 'complete') {
            $this->alerts->reviewRequested($event);
        }

        return $event;
    }

    /** Independent drafts only; a converted or started event is cancelled instead. */
    public function deleteDraft(Event $event): void
    {
        $this->lock($event);

        if ($event->status !== EventStatus::Draft || $event->inquiry_id !== null) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only independent draft events can be deleted; cancel this event instead.', 409);
        }

        $event->sessions()->update(['deleted_at' => now()]);
        $event->delete();
        $this->audit->record('event.draft_deleted', $event, organizationId: $event->organization_id);
    }

    /**
     * @param  array<string, mixed>  $data  name?, starts_at, ends_at (local times in the event timezone)
     */
    public function saveSession(Event $event, ?EventSession $session, array $data): EventSession
    {
        $this->lock($event);
        $this->assertEditable($event);

        $session = $this->writeSession($event, $session ?? new EventSession, $data);
        $this->audit->record($session->wasRecentlyCreated ? 'event.session_added' : 'event.session_updated', $session,
            new: $data, organizationId: $event->organization_id);

        return $session;
    }

    public function deleteSession(Event $event, EventSession $session): void
    {
        $this->lock($event);
        $this->assertEditable($event);

        $remaining = $event->sessions()->where('id', '!=', $session->id)->where('status', '!=', SessionStatus::Cancelled->value)->count();

        if ($event->status !== EventStatus::Draft && $remaining === 0) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'A published event needs at least one session. Cancel the event instead.', 409);
        }

        $session->delete();
        $this->audit->record('event.session_removed', $session, organizationId: $event->organization_id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeSession(Event $event, EventSession $session, array $data): EventSession
    {
        $tz = $event->timezone;
        $starts = CarbonImmutable::parse($data['starts_at'], $tz)->utc();
        $ends = CarbonImmutable::parse($data['ends_at'], $tz)->utc();

        if ($ends <= $starts) {
            throw ValidationException::withMessages(['ends_at' => 'The end time must be after the start time.']);
        }

        // New or moved sessions can't start in the past (times are local to the event).
        if ((! $session->exists || ! $session->starts_at->equalTo($starts)) && $starts->isPast()) {
            throw ValidationException::withMessages(['starts_at' => 'The session must start in the future.']);
        }

        if ($ends->diffInDays($starts, true) > 31) {
            throw ValidationException::withMessages(['ends_at' => 'A session can last at most 31 days; add more sessions for longer events.']);
        }

        $session->fill(['name' => $data['name'] ?? null, 'starts_at' => $starts, 'ends_at' => $ends, 'timezone' => $tz]);

        if (! $session->exists) {
            $session->forceFill(['event_id' => $event->id]);
        }

        $session->save();

        return $session;
    }

    /**
     * B9: cancelling an event voids its unused tickets and QR codes and
     * releases checkout holds (refunds for paid tickets are B10).
     */
    /** @return list<string> the tickets voided */
    private function cancelTickets(Event $event, ?string $reason): array
    {
        return $this->db->system('event.cancel_tickets', function () use ($event, $reason) {
            $tickets = DB::table('tickets')->where('event_id', $event->id)->where('status', 'valid')->pluck('id');
            DB::table('tickets')->whereIn('id', $tickets)->update([
                'status' => 'cancelled', 'cancelled_at' => now(), 'cancellation_reason' => 'event cancelled: '.$reason, 'updated_at' => now(),
            ]);
            DB::table('qr_tickets')->whereIn('ticket_id', $tickets)->where('status', 'active')
                ->update(['status' => 'invalidated', 'invalidated_at' => now(), 'invalidation_reason' => 'event cancelled']);
            DB::table('ticket_holds')->whereIn('ticket_type_id', DB::table('ticket_types')->where('event_id', $event->id)->select('id'))
                ->where('status', 'active')->update(['status' => 'cancelled', 'released_at' => now(), 'updated_at' => now()]);
            DB::table('ticket_types')->where('event_id', $event->id)->update(['cached_sold_count' => 0, 'status' => 'closed', 'updated_at' => now()]);

            return $tickets->all();
        });
    }

    private function assertEditable(Event $event): void
    {
        if ($event->isTerminal()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'A completed or cancelled event can no longer be changed.', 409);
        }
    }

    /** Platform default or the organization's own type (DB trigger enforces too). */
    private function assertEventType(Organization|string $organization, ?string $eventTypeId): void
    {
        if ($eventTypeId === null) {
            return;
        }

        $orgId = $organization instanceof Organization ? $organization->id : $organization;
        $ok = DB::table('event_types')->where('id', $eventTypeId)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', $orgId))->exists();

        if (! $ok) {
            throw ValidationException::withMessages(['event_type_id' => 'Choose a standard event type or one of your own.']);
        }
    }

    private function lock(Event $event): void
    {
        $status = Event::query()->whereKey($event->id)->lockForUpdate()->value('status');
        $event->setRawAttributes(array_merge($event->getAttributes(), ['status' => $status]), true);
    }

    private function past(string $action): string
    {
        return match ($action) {
            'publish' => 'published', 'unpublish' => 'unpublished', 'start' => 'started',
            'complete' => 'completed', 'cancel' => 'cancelled',
        };
    }
}
