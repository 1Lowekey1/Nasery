<?php

namespace App\Services\CheckIn;

use App\Enums\EventStatus;
use App\Enums\TicketStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\OrganizationMember;
use App\Models\Ticket;
use App\Services\Ticketing\QrTokens;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * B11 event-day check-in. A ticket is admitted once per session
 * (attendance_records unique per ticket+session). A session accepts
 * tickets from `nasery.checkin.opens_before_minutes` before it starts
 * until it ends (user). The first admission marks the ticket `used`; a
 * multi-session ticket keeps working for the other sessions.
 *
 * Scanner outcomes are results, not errors: the door needs a clear
 * green/amber/red answer for every scan, so scan() always returns one of
 *   checked_in | duplicate | invalid | wrong_event | cancelled | unpaid
 *   | not_open | event_closed
 * Only unknown tokens and tickets of other events reveal nothing about
 * the ticket. Writes run in the system context with a row lock on the
 * ticket, so two doors scanning the same QR admit it once.
 */
class CheckInDesk
{
    private const TOKEN_PATTERN = '/^NSR1\.[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.[A-Za-z0-9_-]{43}$/';

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly SessionWindow $window,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scan(Event $event, OrganizationMember $by, #[SensitiveParameter] string $token, ?string $sessionId): array
    {
        $token = trim($token);

        if (! preg_match(self::TOKEN_PATTERN, $token)) {
            return $this->outcome('invalid', 'This is not a NaSeRy ticket QR.');
        }

        $qr = $this->db->system('checkin.lookup', fn () => DB::table('qr_tickets')->where('token_hash', QrTokens::hash($token))->first(['ticket_id', 'status']));

        if ($qr === null) {
            // Unknown, or an old QR replaced when the ticket was reissued (the row now holds the new hash).
            return $this->outcome('invalid', 'Ticket not recognized. It may have been replaced; ask the guest for their latest QR.');
        }

        return $this->admit($event, $by, $qr->ticket_id, $sessionId, 'qr_scan', qrInvalidated: $qr->status === 'invalidated');
    }

    /**
     * Check in from the guest list (no QR), e.g. a phone with a dead battery.
     *
     * @return array<string, mixed>
     */
    public function manual(Event $event, OrganizationMember $by, string $ticketId, ?string $sessionId): array
    {
        return $this->admit($event, $by, $ticketId, $sessionId, 'manual');
    }

    public function undo(Event $event, string $attendanceId, string $reason): void
    {
        $ticketId = $this->db->system('checkin.undo', function () use ($event, $attendanceId) {
            $record = DB::table('attendance_records')->where('id', $attendanceId)->where('event_id', $event->id)->first();

            if ($record === null) {
                throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            }

            Ticket::query()->whereKey($record->ticket_id)->lockForUpdate()->first();
            DB::table('attendance_records')->where('id', $attendanceId)->delete();

            if (! DB::table('attendance_records')->where('ticket_id', $record->ticket_id)->exists()) {
                DB::table('tickets')->where('id', $record->ticket_id)->where('status', TicketStatus::Used->value)
                    ->update(['status' => TicketStatus::Valid->value, 'updated_at' => now()]);
            }

            return $record->ticket_id;
        });

        $this->audit->record('checkin.undone', ['tickets', $ticketId], new: ['event_id' => $event->id, 'attendance_id' => $attendanceId],
            organizationId: $event->organization_id, reason: $reason);
    }

    /**
     * Live numbers for the door and the dashboard.
     *
     * @return array<string, mixed>
     */
    public function summary(Event $event, int $recent = 25): array
    {
        return $this->db->system('checkin.summary', function () use ($event, $recent) {
            $expected = (int) DB::table('tickets')->where('event_id', $event->id)->whereNull('deleted_at')
                ->whereIn('status', [TicketStatus::Valid->value, TicketStatus::Used->value])->count();
            $perSession = DB::table('attendance_records')->where('event_id', $event->id)
                ->groupBy('event_session_id')->selectRaw('event_session_id, count(*) as n')->pluck('n', 'event_session_id');

            $tiers = DB::table('ticket_types as tt')->where('tt.event_id', $event->id)->whereNull('tt.deleted_at')
                ->orderBy('tt.sort_order')->orderBy('tt.name')
                ->get(['tt.id', 'tt.name',
                    DB::raw("(select count(*) from tickets t where t.ticket_type_id = tt.id and t.deleted_at is null and t.status in ('valid','used')) as expected"),
                    DB::raw("(select count(*) from tickets t where t.ticket_type_id = tt.id and t.deleted_at is null and t.status = 'used') as checked_in")]);

            $checkedIn = (int) DB::table('tickets')->where('event_id', $event->id)->whereNull('deleted_at')->where('status', TicketStatus::Used->value)->count();
            // B12: people without a ticket (name log + headcount); door sales are tickets, already counted above.
            $walkIns = (int) DB::table('walk_in_records')->where('event_id', $event->id)->whereNull('linked_ticket_id')->sum('count');

            return [
                'expected' => $expected,
                'checked_in' => $checkedIn,
                'walk_ins' => $walkIns,
                'arrived' => $checkedIn + $walkIns,
                'sessions' => $this->window->sessions($event)->map(fn ($s) => [...$this->window->present($s, $event), 'checked_in' => (int) ($perSession[$s->id] ?? 0)])->values(),
                'tiers' => $tiers->map(fn ($t) => ['name' => $t->name, 'expected' => (int) $t->expected, 'checked_in' => (int) $t->checked_in])->values(),
                'recent' => $this->recent($event, $recent),
                'opens_before_minutes' => $this->window->opensBefore(),
                'event_status' => $event->status->value,
            ];
        });
    }

    /**
     * Guest search at the door (manual check-in).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function search(Event $event, string $term): Collection
    {
        $like = '%'.addcslashes(trim($term), '%_\\').'%';

        return $this->db->system('checkin.search', fn () => DB::table('tickets as t')
            ->join('attendees as a', 'a.id', '=', 't.attendee_id')
            ->join('ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
            ->where('t.event_id', $event->id)->whereNull('t.deleted_at')
            ->whereIn('t.status', [TicketStatus::Valid->value, TicketStatus::Used->value, TicketStatus::Cancelled->value])
            ->where(fn ($w) => $w->where('a.full_name', 'ilike', $like)->orWhere('a.email', 'ilike', $like)->orWhere('a.contact_number', 'ilike', $like))
            ->orderBy('a.full_name')->limit(15)
            ->get(['t.id', 't.status', 'a.full_name', 'a.email', 'tt.name as tier', 'tt.zone_name',
                DB::raw('(select max(ar.checked_in_at) from attendance_records ar where ar.ticket_id = t.id) as last_checked_in_at')])
            ->map(fn ($r) => [
                'ticket_id' => $r->id, 'name' => $r->full_name, 'email' => $r->email, 'tier' => $r->tier, 'zone' => $r->zone_name,
                'status' => $r->status, 'last_checked_in_at' => $r->last_checked_in_at ? CarbonImmutable::parse($r->last_checked_in_at)->toIso8601String() : null,
            ]));
    }

    /**
     * Attendance for the client's live view: numbers only, no names.
     *
     * @return array<string, mixed>
     */
    public function forClient(Event $event): array
    {
        $summary = $this->summary($event, 0);

        return [
            'event_status' => $summary['event_status'],
            'expected' => $summary['expected'],
            'checked_in' => $summary['checked_in'],
            'walk_ins' => $summary['walk_ins'],
            'arrived' => $summary['arrived'],
            'sessions' => collect($summary['sessions'])->map(fn ($s) => collect($s)->only(['id', 'label', 'starts_at', 'ends_at', 'is_open', 'checked_in']))->values(),
            'updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function admit(Event $event, OrganizationMember $by, string $ticketId, ?string $sessionId, string $method, bool $qrInvalidated = false): array
    {
        if (! in_array($event->status, [EventStatus::Published, EventStatus::Ongoing], true)) {
            return $this->outcome('event_closed', match ($event->status) {
                EventStatus::Draft => 'This event is not published yet.',
                EventStatus::Cancelled => 'This event was cancelled.',
                default => 'This event has ended.',
            });
        }

        return $this->db->system('checkin.admit', function () use ($event, $by, $ticketId, $sessionId, $method, $qrInvalidated) {
            $ticket = Ticket::query()->whereKey($ticketId)->whereNull('deleted_at')->lockForUpdate()->first();

            if ($ticket === null || $ticket->event_id !== $event->id) {
                return $this->outcome('wrong_event', 'This ticket is not for this event.');
            }

            $guest = $this->guest($ticket->id);

            if ($ticket->status === TicketStatus::Pending) {
                return $this->outcome('unpaid', 'This ticket has not been paid for yet.', $guest);
            }

            if (! in_array($ticket->status, [TicketStatus::Valid, TicketStatus::Used], true)) {
                return $this->outcome('cancelled', 'This ticket was cancelled'.($ticket->cancellation_reason ? " ({$ticket->cancellation_reason})" : '').'.', $guest);
            }

            if ($qrInvalidated) {
                return $this->outcome('invalid', 'This QR code is no longer valid; ask the guest for their latest QR.', $guest);
            }

            $session = $this->window->pick($event, $sessionId);

            if (! $session['open']) {
                return $this->outcome('not_open', $session['message'], $guest);
            }

            $existing = DB::table('attendance_records')->where('ticket_id', $ticket->id)->where('event_session_id', $session['row']->id)->first();

            if ($existing !== null) {
                DB::table('attendance_records')->where('id', $existing->id)->increment('duplicate_attempt_count');

                return $this->outcome('duplicate', 'Already checked in.', $guest, [
                    'session' => $this->window->present($session['row'], $event),
                    'checked_in_at' => CarbonImmutable::parse($existing->checked_in_at)->toIso8601String(),
                    'checked_in_by' => $this->memberName($existing->checked_in_by),
                    'duplicate_attempts' => $existing->duplicate_attempt_count + 1,
                ]);
            }

            $id = (string) Str::uuid7();
            $at = now();
            DB::table('attendance_records')->insert([
                'id' => $id, 'ticket_id' => $ticket->id, 'event_id' => $event->id, 'event_session_id' => $session['row']->id,
                'checked_in_by' => $by->id, 'method' => $method, 'checked_in_at' => $at, 'created_at' => $at,
            ]);

            if ($ticket->status === TicketStatus::Valid) {
                $ticket->forceFill(['status' => TicketStatus::Used])->save();
            }

            return $this->outcome('checked_in', 'Welcome, '.$guest['name'].'!', $guest, [
                'attendance_id' => $id,
                'session' => $this->window->present($session['row'], $event),
                'checked_in_at' => $at->toIso8601String(),
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    private function recent(Event $event, int $limit): array
    {
        if ($limit === 0) {
            return [];
        }

        return DB::table('attendance_records as ar')
            ->join('tickets as t', 't.id', '=', 'ar.ticket_id')
            ->join('attendees as a', 'a.id', '=', 't.attendee_id')
            ->join('ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
            ->where('ar.event_id', $event->id)
            ->orderByDesc('ar.checked_in_at')->limit($limit)
            ->get(['ar.id', 'ar.method', 'ar.checked_in_at', 'ar.checked_in_by', 'ar.duplicate_attempt_count', 'ar.event_session_id', 'a.full_name', 'tt.name as tier', 'tt.zone_name'])
            ->map(fn ($r) => [
                'id' => $r->id, 'name' => $r->full_name, 'tier' => $r->tier, 'zone' => $r->zone_name, 'method' => $r->method,
                'session_id' => $r->event_session_id, 'checked_in_at' => CarbonImmutable::parse($r->checked_in_at)->toIso8601String(),
                'checked_in_by' => $this->memberName($r->checked_in_by), 'duplicate_attempts' => (int) $r->duplicate_attempt_count,
            ])->all();
    }

    /** @return array{name: string, tier: string, zone: ?string, benefits: list<string>} */
    private function guest(string $ticketId): array
    {
        $r = DB::table('tickets as t')->join('attendees as a', 'a.id', '=', 't.attendee_id')->join('ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
            ->where('t.id', $ticketId)->first(['a.full_name', 'tt.name', 'tt.zone_name', 'tt.benefits']);

        return ['name' => $r->full_name, 'tier' => $r->name, 'zone' => $r->zone_name, 'benefits' => json_decode((string) $r->benefits, true) ?? []];
    }

    private function memberName(?string $memberId): ?string
    {
        if ($memberId === null) {
            return null;
        }

        return DB::table('organization_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.id', $memberId)
            ->selectRaw("trim(concat(u.first_name, ' ', u.last_name)) as name")->value('name');
    }

    /**
     * @param  array<string, mixed>|null  $guest
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function outcome(string $result, string $message, ?array $guest = null, array $extra = []): array
    {
        return ['result' => $result, 'message' => $message, 'guest' => $guest, ...$extra];
    }
}
