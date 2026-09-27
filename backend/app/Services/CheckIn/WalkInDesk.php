<?php

namespace App\Services\CheckIn;

use App\Enums\AdmissionType;
use App\Enums\EventStatus;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Enums\TicketStatus;
use App\Enums\WalkInCategory;
use App\Exceptions\ApiException;
use App\Models\Attendee;
use App\Models\Event;
use App\Models\OrganizationMember;
use App\Models\Registration;
use App\Models\TicketType;
use App\Services\Ticketing\CapacityGuard;
use App\Services\Ticketing\TicketIssuer;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\Notifications\Alerts;

/**
 * B12 walk-in counter. Three ways in, whichever suits the event (user):
 *  - door sale: a real ticket in a tier (source walk_in, cash or free),
 *    checked in at once; tier AND venue capacity apply; recorded as a
 *    `ticket_linked` walk-in (adds nothing extra to capacity, 0015)
 *  - name log: a named person without a ticket (`registered_attendee`)
 *  - headcount: anonymous people counted by staff (`headcount_only`, n ≥ 1)
 * The last two count against the venue's overall capacity only.
 *
 * Full venue (user, "a mix"): refused from 100% for door staff; someone who
 * may edit the event can admit anyway with a reason (audited, reported as
 * "over capacity by N"). A full tier is always a hard stop. The door must be
 * open (SessionWindow) and the event published or ongoing.
 */
class WalkInDesk
{
    public const WARNING_PERCENT = 90;

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly SessionWindow $window,
        private readonly CapacityGuard $capacity,
        private readonly TicketIssuer $issuer,
        private readonly Alerts $alerts,
    ) {}

    /**
     * @param  array{full_name: string, email?: ?string, contact_number?: ?string}  $person
     * @return array<string, mixed>
     */
    public function sell(Event $event, OrganizationMember $by, TicketType $tier, array $person, ?string $sessionId, ?string $overrideReason): array
    {
        $session = $this->openSession($event, $sessionId);
        $admission = (float) $tier->price > 0 ? AdmissionType::Paid : AdmissionType::Free;

        $result = $this->db->system('walkin.sell', function () use ($event, $by, $tier, $person, $session, $admission, $overrideReason) {
            $over = $this->capacity->reserve($event, $tier, 1, allowEventOverflow: $overrideReason !== null);

            $registration = Registration::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'event_id' => $event->id,
                'purchaser_name' => trim($person['full_name']),
                'purchaser_email' => $person['email'] ?? null,
                'purchaser_contact' => $person['contact_number'] ?? null,
                'registration_source' => RegistrationSource::WalkIn,
                'status' => RegistrationStatus::Confirmed,
                'created_by_member_id' => $by->id,
            ]);
            $ticket = $this->issuer->issue($registration, $tier, [$person], $admission)->first();

            $now = now();
            DB::table('attendance_records')->insert([
                'id' => (string) Str::uuid7(), 'ticket_id' => $ticket->id, 'event_id' => $event->id, 'event_session_id' => $session->id,
                'checked_in_by' => $by->id, 'method' => 'manual', 'checked_in_at' => $now, 'created_at' => $now,
            ]);
            $ticket->forceFill(['status' => TicketStatus::Used])->save();
            $this->issuer->refreshSoldCount($tier->id);

            $id = $this->insert($event, $by, $session, WalkInCategory::TicketLinked, 1, $ticket->attendee_id, $ticket->id);

            return ['id' => $id, 'over' => $over, 'ticket_id' => $ticket->id, 'price' => $admission === AdmissionType::Paid ? (string) $tier->price : '0.00'];
        });

        $this->auditOverride($event, $result['id'], $result['over'], $overrideReason);
        if (filled($person['email'] ?? null)) {
            $this->alerts->ticketsIssued([$result['ticket_id']], null, $event->name); // their QR, for re-entry or later sessions
        }
        $this->alerts->capacity($event);

        return [
            'walk_in_id' => $result['id'],
            'ticket_id' => $result['ticket_id'],
            'amount_due' => $result['price'],
            'over_capacity_by' => $result['over'],
            'message' => trim($person['full_name']).' is in'.((float) $result['price'] > 0 ? ' (paid ₱'.number_format((float) $result['price'], 2).' cash)' : '').'.',
        ];
    }

    /**
     * @param  array{full_name: string, email?: ?string, contact_number?: ?string}  $person
     * @return array<string, mixed>
     */
    public function logName(Event $event, OrganizationMember $by, array $person, ?string $sessionId, ?string $overrideReason): array
    {
        $session = $this->openSession($event, $sessionId);

        $result = $this->db->system('walkin.log', function () use ($event, $by, $person, $session, $overrideReason) {
            $over = $this->capacity->reserveEventPlaces($event, 1, $overrideReason !== null);
            $attendee = Attendee::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'full_name' => trim($person['full_name']),
                'email' => filled($person['email'] ?? null) ? Str::lower(trim($person['email'])) : null,
                'contact_number' => $person['contact_number'] ?? null,
            ]);

            return ['id' => $this->insert($event, $by, $session, WalkInCategory::RegisteredAttendee, 1, $attendee->id), 'over' => $over];
        });

        $this->auditOverride($event, $result['id'], $result['over'], $overrideReason);
        $this->alerts->capacity($event);

        return ['walk_in_id' => $result['id'], 'over_capacity_by' => $result['over'], 'message' => trim($person['full_name']).' is in.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function headcount(Event $event, OrganizationMember $by, int $count, ?string $sessionId, ?string $overrideReason): array
    {
        $session = $this->openSession($event, $sessionId);

        $result = $this->db->system('walkin.headcount', function () use ($event, $by, $count, $session, $overrideReason) {
            $over = $this->capacity->reserveEventPlaces($event, $count, $overrideReason !== null);

            return ['id' => $this->insert($event, $by, $session, WalkInCategory::HeadcountOnly, $count), 'over' => $over];
        });

        $this->auditOverride($event, $result['id'], $result['over'], $overrideReason);
        $this->alerts->capacity($event);

        return ['walk_in_id' => $result['id'], 'over_capacity_by' => $result['over'], 'message' => "+{$count} counted."];
    }

    /** Remove a mistaken entry; a door sale's ticket is cancelled with it. */
    public function undo(Event $event, string $walkInId, string $reason): void
    {
        $row = $this->db->system('walkin.undo', function () use ($event, $walkInId, $reason) {
            $row = DB::table('walk_in_records')->where('id', $walkInId)->where('event_id', $event->id)->lockForUpdate()->first();

            if ($row === null) {
                throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            }

            DB::table('walk_in_records')->where('id', $walkInId)->delete();

            if ($row->linked_ticket_id !== null) {
                $ticket = DB::table('tickets')->where('id', $row->linked_ticket_id)->lockForUpdate()->first(['id', 'ticket_type_id']);
                DB::table('attendance_records')->where('ticket_id', $ticket->id)->delete();
                DB::table('tickets')->where('id', $ticket->id)->update([
                    'status' => TicketStatus::Cancelled->value, 'cancelled_at' => now(), 'cancellation_reason' => 'walk-in undone: '.$reason, 'updated_at' => now(),
                ]);
                DB::table('qr_tickets')->where('ticket_id', $ticket->id)->update(['status' => 'invalidated', 'invalidated_at' => now(), 'invalidation_reason' => 'walk-in undone']);
                $this->issuer->refreshSoldCount($ticket->ticket_type_id);
            }

            return $row;
        });

        $this->audit->record('walkin.undone', ['walk_in_records', $walkInId],
            old: ['category' => $row->category, 'count' => $row->count, 'ticket_id' => $row->linked_ticket_id],
            new: ['event_id' => $event->id], organizationId: $event->organization_id, reason: $reason);
    }

    /**
     * The counter's live picture.
     *
     * @return array<string, mixed>
     */
    public function summary(Event $event, int $recent = 25): array
    {
        return $this->db->system('walkin.summary', function () use ($event, $recent) {
            $totals = DB::table('walk_in_records')->where('event_id', $event->id)
                ->selectRaw("coalesce(sum(count) filter (where category = 'ticket_linked'), 0) as door_sales,
                    coalesce(sum(count) filter (where category in ('registered_attendee', 'paid_walk_in')), 0) as names,
                    coalesce(sum(count) filter (where category = 'headcount_only'), 0) as headcount")->first();
            $doorRevenue = DB::table('walk_in_records as w')->join('tickets as t', 't.id', '=', 'w.linked_ticket_id')
                ->where('w.event_id', $event->id)->sum('t.purchased_price');

            $tiers = DB::table('ticket_types')->where('event_id', $event->id)->whereNull('deleted_at')
                ->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'zone_name', 'price', 'status', DB::raw('greatest(0, capacity - ticket_type_consumed_capacity(id)) as remaining')]);

            return [
                'capacity' => $this->capacityState($event),
                'totals' => [
                    'door_sales' => (int) $totals->door_sales,
                    'names' => (int) $totals->names,
                    'headcount' => (int) $totals->headcount,
                    'walk_ins' => (int) $totals->door_sales + (int) $totals->names + (int) $totals->headcount,
                    'door_revenue' => number_format((float) $doorRevenue, 2, '.', ''),
                ],
                'tiers' => $tiers->map(fn ($t) => [
                    'id' => $t->id, 'name' => $t->name, 'zone' => $t->zone_name, 'price' => $t->price, 'status' => $t->status, 'remaining' => (int) $t->remaining,
                ])->values(),
                'sessions' => $this->window->sessions($event)->map(fn ($s) => $this->window->present($s, $event))->values(),
                'recent' => $this->recent($event, $recent),
                'event_status' => $event->status->value,
            ];
        });
    }

    /**
     * Overall venue capacity: used by tickets, live holds and walk-ins.
     *
     * @return array{limit: ?int, used: int, remaining: ?int, percent: ?int, level: string, warning_percent: int}
     */
    public function capacityState(Event $event): array
    {
        $limit = DB::table('events')->where('id', $event->id)->value('overall_capacity');
        $used = (int) DB::scalar('select event_consumed_capacity(?)', [$event->id]);

        if ($limit === null) {
            return ['limit' => null, 'used' => $used, 'remaining' => null, 'percent' => null, 'level' => 'unlimited', 'warning_percent' => self::WARNING_PERCENT];
        }

        $percent = $limit > 0 ? (int) floor($used * 100 / $limit) : 100;

        return [
            'limit' => (int) $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'percent' => $percent,
            'level' => match (true) {
                $used > $limit => 'over',
                $used >= $limit => 'full',
                $percent >= self::WARNING_PERCENT => 'warning',
                default => 'ok',
            },
            'warning_percent' => self::WARNING_PERCENT,
        ];
    }

    private function openSession(Event $event, ?string $sessionId): object
    {
        if (! in_array($event->status, [EventStatus::Published, EventStatus::Ongoing], true)) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, 'Walk-ins can be recorded once the event is published.', 409);
        }

        $session = $this->window->pick($event, $sessionId);

        if (! $session['open']) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, $session['message'], 409);
        }

        return $session['row'];
    }

    private function insert(Event $event, OrganizationMember $by, object $session, WalkInCategory $category, int $count, ?string $attendeeId = null, ?string $ticketId = null): string
    {
        $id = (string) Str::uuid7();
        $now = now();
        DB::table('walk_in_records')->insert([
            'id' => $id, 'event_id' => $event->id, 'event_session_id' => $session->id, 'attendee_id' => $attendeeId, 'linked_ticket_id' => $ticketId,
            'category' => $category->value, 'count' => $count, 'recorded_by' => $by->id, 'recorded_at' => $now, 'created_at' => $now,
        ]);

        return $id;
    }

    private function auditOverride(Event $event, string $walkInId, int $over, ?string $reason): void
    {
        if ($over > 0) {
            $this->audit->record('walkin.capacity_override', ['walk_in_records', $walkInId], new: ['event_id' => $event->id, 'over_by' => $over],
                organizationId: $event->organization_id, reason: $reason);
        }
    }

    /** @return list<array<string, mixed>> */
    private function recent(Event $event, int $limit): array
    {
        return DB::table('walk_in_records as w')
            ->leftJoin('attendees as a', 'a.id', '=', 'w.attendee_id')
            ->leftJoin('tickets as t', 't.id', '=', 'w.linked_ticket_id')
            ->leftJoin('ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
            ->leftJoin('organization_members as m', 'm.id', '=', 'w.recorded_by')
            ->leftJoin('users as u', 'u.id', '=', 'm.user_id')
            ->where('w.event_id', $event->id)
            ->orderByDesc('w.recorded_at')->limit($limit)
            ->get(['w.id', 'w.category', 'w.count', 'w.recorded_at', 'a.full_name', 'tt.name as tier', 't.purchased_price',
                DB::raw("nullif(trim(concat(u.first_name, ' ', u.last_name)), '') as recorded_by")])
            ->map(fn ($r) => [
                'id' => $r->id, 'category' => $r->category, 'count' => (int) $r->count, 'name' => $r->full_name, 'tier' => $r->tier,
                'amount' => $r->purchased_price, 'recorded_by' => $r->recorded_by,
                'recorded_at' => CarbonImmutable::parse($r->recorded_at)->toIso8601String(),
            ])->all();
    }
}
