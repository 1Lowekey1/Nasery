<?php

namespace App\Services\Ticketing;

use App\Enums\AdmissionType;
use App\Enums\HoldStatus;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\QrTicket;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketHold;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Notifications\Alerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Public events and self-registration (B9). Guests browse only the 0036
 * public views; registering needs a signed-in, verified account (user
 * decision). Free tiers issue tickets at once; paid tiers reserve places
 * with a 15-minute hold and a pending registration that B10's checkout
 * completes. All reads/writes run in the system context with the public
 * view as the gate (the attendee isn't an organization member).
 */
class SelfRegistration
{
    public const MAX_PER_REGISTRATION = 10;

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly CapacityGuard $capacity,
        private readonly TicketIssuer $issuer,
        private readonly QrTokens $qr,
        private readonly Alerts $alerts,
        private readonly PlatformSettings $settings,
    ) {}

    /** Checkout hold length, set by NaSeRy admins (platform_settings). */
    public function holdMinutes(): int
    {
        return $this->settings->holdMinutes();
    }

    /**
     * @param  array{search?: ?string, page?: int}  $filters
     */
    public function events(array $filters): LengthAwarePaginator
    {
        return $this->db->system('public.events', function () use ($filters) {
            $page = DB::table('public_events')
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->when(filled($filters['search'] ?? null), function ($q) use ($filters) {
                    $term = '%'.addcslashes(trim($filters['search']), '%_\\').'%';
                    $q->where(fn ($w) => $w->where('name', 'ilike', $term)->orWhere('organizer_name', 'ilike', $term)
                        ->orWhere('venue_address', 'ilike', $term)->orWhere('event_type', 'ilike', $term));
                })
                ->orderByRaw('starts_at asc nulls last')
                ->paginate(perPage: 12, page: (int) ($filters['page'] ?? 1));

            $prices = DB::table('public_ticket_types')->whereIn('event_id', $page->getCollection()->pluck('id'))
                ->selectRaw('event_id, min(price) as from_price, count(*) as tiers')->groupBy('event_id')->get()->keyBy('event_id');

            return $page->through(fn ($e) => [
                ...$this->eventCard($e),
                'from_price' => $prices[$e->id]->from_price ?? null,
                'tiers' => (int) ($prices[$e->id]->tiers ?? 0),
            ]);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function event(string $eventId): ?array
    {
        return $this->db->system('public.event', function () use ($eventId) {
            $e = DB::table('public_events')->where('id', $eventId)->first();

            if ($e === null) {
                return null;
            }

            $tiers = DB::table('public_ticket_types')->where('event_id', $eventId)->orderBy('sort_order')->orderBy('price')->get();
            $remaining = $this->capacity->remaining($tiers->pluck('id')->all());
            $sessions = DB::table('event_sessions')->where('event_id', $eventId)->whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')->orderBy('starts_at')->get(['name', 'starts_at', 'ends_at']);
            $now = now();

            return [
                ...$this->eventCard($e),
                'description' => $e->description,
                'latitude' => $e->latitude !== null ? (float) $e->latitude : null,
                'longitude' => $e->longitude !== null ? (float) $e->longitude : null,
                'sessions' => $sessions->map(fn ($s) => [
                    'name' => $s->name,
                    'starts_at' => CarbonImmutable::parse($s->starts_at)->setTimezone($e->timezone)->toIso8601String(),
                    'ends_at' => CarbonImmutable::parse($s->ends_at)->setTimezone($e->timezone)->toIso8601String(),
                ])->values(),
                'tiers' => $tiers->map(function ($t) use ($remaining, $now, $e) {
                    $opens = $t->sales_start_at ? CarbonImmutable::parse($t->sales_start_at) : null;
                    $closes = $t->sales_end_at ? CarbonImmutable::parse($t->sales_end_at) : null;
                    $left = $remaining[$t->id] ?? 0;

                    return [
                        'id' => $t->id,
                        'name' => $t->name,
                        'description' => $t->description,
                        'zone_name' => $t->zone_name,
                        'benefits' => json_decode((string) $t->benefits, true) ?? [],
                        'price' => $t->price,
                        'currency' => $t->currency,
                        'remaining' => $left,
                        'sales_start_at' => $opens?->setTimezone($e->timezone)->toIso8601String(),
                        'sales_end_at' => $closes?->setTimezone($e->timezone)->toIso8601String(),
                        'availability' => match (true) {
                            $opens !== null && $opens->isAfter($now) => 'not_yet',
                            $closes !== null && $closes->isBefore($now) => 'ended',
                            $left <= 0 => 'sold_out',
                            default => 'available',
                        },
                    ];
                })->values(),
            ];
        });
    }

    /**
     * @param  list<array{full_name: string, email?: ?string}>  $attendees
     * @return array<string, mixed>
     */
    public function register(User $user, string $eventId, string $tierId, array $attendees): array
    {
        $result = $this->db->system('public.register', function () use ($user, $eventId, $tierId, $attendees) {
            // The public views are the gate: public, published, approved organizer, tier on sale.
            $publicTier = DB::table('public_ticket_types')->where('id', $tierId)->where('event_id', $eventId)->first();

            if ($publicTier === null) {
                throw new ApiException(ErrorCode::NotFound, 'This ticket is not available.', 404);
            }

            $now = now();
            if (($publicTier->sales_start_at && CarbonImmutable::parse($publicTier->sales_start_at)->isAfter($now))
                || ($publicTier->sales_end_at && CarbonImmutable::parse($publicTier->sales_end_at)->isBefore($now))) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'Sales for this ticket are not open.', 409);
            }

            $event = Event::query()->findOrFail($eventId);
            $tier = TicketType::query()->findOrFail($tierId);
            $this->capacity->reserve($event, $tier, count($attendees));

            $registration = Registration::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'event_id' => $event->id,
                'purchaser_user_id' => $user->id,
                'purchaser_name' => trim($user->first_name.' '.$user->last_name),
                'purchaser_email' => $user->email,
                'purchaser_contact' => $user->phone,
                'registration_source' => RegistrationSource::Online,
                'status' => $tier->isFree() ? RegistrationStatus::Confirmed : RegistrationStatus::Pending,
            ]);

            if ($tier->isFree()) {
                $tickets = $this->issuer->issue($registration, $tier, $attendees, AdmissionType::Free);

                return ['registration' => $registration, 'event' => $event, 'hold' => null, 'tickets' => $tickets->pluck('id')->all()];
            }

            // Paid: reserve the places and record who is going; checkout (B10) issues the tickets.
            $this->issuer->issuePending($registration, $tier, $attendees);
            $hold = TicketHold::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'registration_id' => $registration->id,
                'ticket_type_id' => $tier->id,
                'event_id' => $event->id,
                'quantity' => count($attendees),
                'expires_at' => $now->copy()->addMinutes($this->holdMinutes()),
                'status' => HoldStatus::Active,
            ]);

            return ['registration' => $registration, 'event' => $event, 'hold' => $hold];
        });

        $this->audit->record('registration.created', $result['registration'],
            new: ['event_id' => $eventId, 'tier_id' => $tierId, 'quantity' => count($attendees), 'paid' => $result['hold'] !== null],
            organizationId: $result['event']->organization_id);

        if (isset($result['tickets'])) {
            $this->alerts->ticketsIssued($result['tickets'], $user->id, $result['event']->name);
        }
        $this->alerts->capacity($result['event']);

        return [
            'registration_id' => $result['registration']->id,
            'status' => $result['hold'] ? 'awaiting_payment' : 'confirmed',
            'hold_expires_at' => $result['hold']?->expires_at->toIso8601String(),
        ];
    }

    /**
     * The signed-in user's tickets with QR tokens, newest events first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function myTickets(User $user): Collection
    {
        return $this->db->system('me.tickets', function () use ($user) {
            $rows = DB::table('tickets as t')
                ->join('registrations as r', 'r.id', '=', 't.registration_id')
                ->join('attendees as a', 'a.id', '=', 't.attendee_id')
                ->join('ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
                ->join('events as e', 'e.id', '=', 't.event_id')
                ->join('organizations as o', 'o.id', '=', 'e.organization_id')
                ->where('r.purchaser_user_id', $user->id)->whereNull('t.deleted_at')
                ->where('t.status', '!=', TicketStatus::Pending->value)
                ->orderByDesc('t.created_at')->limit(200)
                ->get(['t.id', 't.status', 't.admission_type', 't.event_id', 'a.full_name', 'tt.name as tier', 'tt.zone_name', 'tt.benefits',
                    'e.name as event', 'e.status as event_status', 'e.timezone', 'e.venue_name', 'e.venue_address', 'o.display_name as organizer']);

            $qrs = QrTicket::query()->whereIn('ticket_id', $rows->pluck('id'))->where('status', 'active')->get()->keyBy('ticket_id');
            $starts = DB::table('event_sessions')->whereIn('event_id', $rows->pluck('event_id')->unique())->whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')->groupBy('event_id')->selectRaw('event_id, min(starts_at) as starts_at')->pluck('starts_at', 'event_id');

            return $rows->map(fn ($r) => [
                'id' => $r->id,
                'status' => $r->status,
                'attendee' => $r->full_name,
                'tier' => $r->tier,
                'zone' => $r->zone_name,
                'benefits' => json_decode((string) $r->benefits, true) ?? [],
                'event_id' => $r->event_id,
                'event' => $r->event,
                'event_status' => $r->event_status,
                'organizer' => $r->organizer,
                'venue' => $r->venue_name ?? $r->venue_address,
                'starts_at' => isset($starts[$r->event_id]) ? CarbonImmutable::parse($starts[$r->event_id])->setTimezone($r->timezone)->toIso8601String() : null,
                // Holders may cancel unused free tickets themselves; paid ones go through the organizer.
                'cancellable' => $r->status === TicketStatus::Valid->value && $r->admission_type === AdmissionType::Free->value,
                // Shown while the ticket can still admit: a checked-in ticket still opens the event's other sessions.
                'qr_token' => in_array($r->status, [TicketStatus::Valid->value, TicketStatus::Used->value], true) && ! in_array($r->event_status, ['completed', 'cancelled'], true) && isset($qrs[$r->id]) ? $this->qr->token($qrs[$r->id]) : null,
            ])->values();
        });
    }

    /**
     * The user's paid registrations still waiting for payment (hold active).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingFor(User $user): Collection
    {
        return $this->db->system('me.pending_registrations', fn () => DB::table('registrations as r')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('ticket_holds as h', 'h.registration_id', '=', 'r.id')
            ->join('ticket_types as tt', 'tt.id', '=', 'h.ticket_type_id')
            ->where('r.purchaser_user_id', $user->id)->where('r.status', 'pending')
            ->where('h.status', 'active')->where('h.expires_at', '>', now())
            ->orderBy('h.expires_at')
            ->get(['r.id', 'e.id as event_id', 'e.name as event', 'tt.name as tier', 'tt.price', 'h.quantity', 'h.expires_at'])
            ->map(fn ($r) => [
                'registration_id' => $r->id, 'event_id' => $r->event_id, 'event' => $r->event, 'tier' => $r->tier,
                'quantity' => (int) $r->quantity, 'amount' => number_format((float) $r->price * $r->quantity, 2, '.', ''),
                'hold_expires_at' => CarbonImmutable::parse($r->expires_at)->toIso8601String(),
            ]));
    }

    /** A holder may cancel their own free ticket before the event starts. */
    /** Whether the user bought this ticket (their registration). */
    public function owns(User $user, string $ticketId): bool
    {
        return $this->db->system('me.owns_ticket', fn () => DB::table('tickets as t')->join('registrations as r', 'r.id', '=', 't.registration_id')
            ->where('t.id', $ticketId)->where('r.purchaser_user_id', $user->id)->whereNull('t.deleted_at')->exists());
    }

    public function cancelMine(User $user, string $ticketId): void
    {
        $this->db->system('me.cancel_ticket', function () use ($user, $ticketId) {
            $ticket = Ticket::query()->whereKey($ticketId)->lockForUpdate()->first();
            $owns = $ticket && DB::table('registrations')->where('id', $ticket->registration_id)->where('purchaser_user_id', $user->id)->exists();

            if (! $owns) {
                throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            }

            if ($ticket->status !== TicketStatus::Valid || $ticket->admission_type !== AdmissionType::Free) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'Only an unused free ticket can be cancelled here; contact the organizer.', 409);
            }

            $ticket->forceFill(['status' => TicketStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => 'cancelled by holder'])->save();
            DB::table('qr_tickets')->where('ticket_id', $ticket->id)->update(['status' => 'invalidated', 'invalidated_at' => now(), 'invalidation_reason' => 'ticket cancelled']);
            $this->issuer->refreshSoldCount($ticket->ticket_type_id);
        });

        $this->audit->record('ticket.cancelled_by_holder', ['tickets', $ticketId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventCard(object $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'event_type' => $e->event_type,
            'status' => $e->status,
            'organizer' => ['name' => $e->organizer_name, 'slug' => $e->organizer_slug],
            'venue_name' => $e->venue_name,
            'venue_address' => $e->venue_address,
            'timezone' => $e->timezone,
            'starts_at' => $e->starts_at ? CarbonImmutable::parse($e->starts_at)->setTimezone($e->timezone)->toIso8601String() : null,
            'ends_at' => $e->ends_at ? CarbonImmutable::parse($e->ends_at)->setTimezone($e->timezone)->toIso8601String() : null,
        ];
    }
}
