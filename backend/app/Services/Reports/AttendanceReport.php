<?php

namespace App\Services\Reports;

use App\Models\Event;
use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * B14 post-event attendance report, computed from what happened (paper:
 * total attendance, per-tier breakdown, no-shows, walk-ins, check-in
 * timeline). Runs in the system context; callers authorize first.
 *
 * Definitions:
 *  - expected   tickets that could admit someone (valid or used), including
 *               door-sale tickets; cancelled tickets are listed separately
 *  - attended   of those, tickets checked in to at least one session
 *  - no-show    expected but never checked in to any session (user)
 *  - walk-ins   people let in without a ticket (names logged + headcount);
 *               door sales are tickets and already counted as attended
 *  - people     attended + walk-ins
 */
class AttendanceReport
{
    private const BUCKET_MINUTES = 15;

    private const MAX_BUCKETS = 96;

    public function __construct(private readonly DatabaseContext $db) {}

    /**
     * The rest of the event's story for the PDF: who it was for, what was
     * agreed, the program, preparation and the client's review. The price
     * needs Finance on the organizer's side (the client agreed to it, so they
     * always see it). Clients only get program items marked for them.
     *
     * @return array<string, mixed>
     */
    private function summary(Event $event, string $tz, bool $withPackage, bool $forClient): array
    {
        $inquiry = $event->inquiry_id ? DB::table('inquiries')->where('id', $event->inquiry_id)->first(['client_user_id', 'guest_count', 'event_type_id']) : null;
        $typeId = $event->event_type_id ?? $inquiry?->event_type_id;
        $client = $inquiry ? DB::table('users')->where('id', $inquiry->client_user_id)->first(['first_name', 'last_name']) : null;

        $tasks = DB::table('tasks as t')->join('checklists as c', 'c.id', '=', 't.checklist_id')
            ->where('c.event_id', $event->id)->whereNull('c.deleted_at')->whereNull('t.deleted_at')
            ->where('t.status', '!=', 'cancelled')->pluck('t.status');

        $program = DB::table('event_activities')->where('event_id', $event->id)->whereNull('deleted_at')
            ->when($forClient, fn ($q) => $q->where('visible_to_client', true))
            ->orderByRaw('activity_at nulls last')->orderBy('created_at')
            ->get(['title', 'person', 'status', 'activity_at']);

        $review = DB::table('reviews')->where('event_id', $event->id)->whereNull('deleted_at')
            ->where('status', 'published')->orderByDesc('created_at')->first(['rating', 'written_review']);

        return [
            'client' => $client ? trim("{$client->first_name} {$client->last_name}") : null,
            'event_type' => $typeId ? DB::table('event_types')->where('id', $typeId)->value('name') : null,
            'requested_guests' => $inquiry?->guest_count,
            'description' => $event->description,
            'package' => $withPackage ? $this->package($event) : null,
            'tasks' => ['done' => $tasks->filter(fn ($s) => $s === 'done')->count(), 'total' => $tasks->count()],
            'program' => $program->map(fn ($a) => [
                'title' => $a->title,
                'when' => $a->activity_at ? $this->local($a->activity_at, $tz) : null,
                'person' => $a->person,
                'status' => $a->status,
            ])->values()->all(),
            'review' => $review ? ['rating' => (int) $review->rating, 'text' => $review->written_review] : null,
        ];
    }

    /** @return array<string, mixed>|null the accepted offer and the final agreed price */
    private function package(Event $event): ?array
    {
        $quotation = $event->accepted_quotation_id
            ? DB::table('quotations')->where('id', $event->accepted_quotation_id)->first(['id', 'package_name', 'total_amount'])
            : null;
        if ($quotation === null && $event->agreed_amount === null) {
            return null;
        }

        return [
            'name' => $quotation?->package_name,
            'items' => $quotation ? DB::table('quotation_items')->where('quotation_id', $quotation->id)->orderBy('created_at')
                ->get(['description', 'quantity', 'subtotal'])->map(fn ($i) => [
                    'description' => $i->description, 'quantity' => (float) $i->quantity, 'subtotal' => $i->subtotal,
                ])->values()->all() : [],
            'quoted_total' => $quotation?->total_amount,
            'agreed_amount' => $event->agreed_amount,
            'agreed_note' => $event->agreed_amount_note,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Event $event, bool $withRevenue, bool $withAttendees = true, bool $forClient = false): array
    {
        return $this->db->system('report.build', function () use ($event, $withRevenue, $withAttendees, $forClient) {
            $tz = $event->timezone ?: 'Asia/Manila';
            $sessions = DB::table('event_sessions')->where('event_id', $event->id)->whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')->orderBy('starts_at')->get(['id', 'name', 'starts_at', 'ends_at']);

            $tickets = DB::table('tickets as t')
                ->join('attendees as a', 'a.id', '=', 't.attendee_id')
                ->join('ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
                ->join('registrations as r', 'r.id', '=', 't.registration_id')
                ->where('t.event_id', $event->id)->whereNull('t.deleted_at')->where('t.status', '!=', 'pending')
                ->orderBy('a.full_name')
                ->get(['t.id', 't.status', 't.ticket_type_id', 'a.full_name', 'a.email', 'tt.name as tier', 'tt.zone_name', 'tt.sort_order', 'r.registration_source']);

            $attendance = DB::table('attendance_records')->where('event_id', $event->id)
                ->get(['ticket_id', 'event_session_id', 'method', 'checked_in_at', 'duplicate_attempt_count']);
            $byTicket = $attendance->groupBy('ticket_id');
            $walkIns = DB::table('walk_in_records as w')->leftJoin('attendees as a', 'a.id', '=', 'w.attendee_id')
                ->where('w.event_id', $event->id)->orderBy('w.recorded_at')
                ->get(['w.category', 'w.count', 'w.event_session_id', 'w.recorded_at', 'a.full_name']);

            $countable = $tickets->filter(fn ($t) => in_array($t->status, ['valid', 'used'], true));
            $attended = $countable->filter(fn ($t) => $byTicket->has($t->id));
            $unticketed = $walkIns->where('category', '!=', 'ticket_linked');
            $walkInPeople = (int) $unticketed->sum('count');
            $expected = $countable->count();
            $capacity = $event->overall_capacity;

            $report = [
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'organizer' => DB::table('organizations')->where('id', $event->organization_id)->value('display_name'),
                    'venue' => trim(implode(', ', array_filter([$event->venue_name, $event->venue_address]))) ?: null,
                    'status' => $event->status->value,
                    'visibility' => $event->visibility,
                    'timezone' => $tz,
                    'sessions' => $sessions->map(fn ($s) => [
                        'id' => $s->id,
                        'label' => $s->name ?: $this->local($s->starts_at, $tz)->format('D, M j'),
                        'when' => $this->local($s->starts_at, $tz)->format('D, M j, Y · g:i A').' – '.$this->local($s->ends_at, $tz)->format('g:i A'),
                    ])->values()->all(),
                ],
                'final' => $event->status->value === 'completed',
                'generated_at' => now()->setTimezone($tz)->toIso8601String(),
                'totals' => [
                    'expected' => $expected,
                    'attended' => $attended->count(),
                    'no_shows' => $expected - $attended->count(),
                    'attendance_rate' => $expected > 0 ? round($attended->count() * 100 / $expected, 1) : null,
                    'walk_ins' => $walkInPeople,
                    'door_sales' => (int) $walkIns->where('category', 'ticket_linked')->sum('count'),
                    'people' => $attended->count() + $walkInPeople,
                    'capacity' => $capacity,
                    'capacity_used' => $capacity ? round(($attended->count() + $walkInPeople) * 100 / $capacity, 1) : null,
                    'cancelled_tickets' => $tickets->where('status', 'cancelled')->count(),
                    'manual_check_ins' => $attendance->where('method', 'manual')->count(),
                    'repeat_scans' => (int) $attendance->sum('duplicate_attempt_count'),
                ],
                'tiers' => $this->tiers($countable, $byTicket),
                'sources' => $this->sources($countable, $byTicket),
                'sessions' => $sessions->map(fn ($s) => [
                    'label' => $s->name ?: $this->local($s->starts_at, $tz)->format('D, M j'),
                    'checked_in' => $attendance->where('event_session_id', $s->id)->count(),
                    'walk_ins' => (int) $unticketed->where('event_session_id', $s->id)->sum('count'),
                ])->values()->all(),
                'timeline' => $this->timeline($attendance, $unticketed, $tz),
                'summary' => $this->summary($event, $tz, $withRevenue || $forClient, $forClient),
            ];

            if ($withRevenue) {
                $report['revenue'] = $this->revenue($event);
            }

            if ($withAttendees) {
                $report['attendees'] = $tickets->map(function ($t) use ($byTicket, $tz) {
                    $records = $byTicket->get($t->id, collect())->sortBy('checked_in_at');

                    return [
                        'name' => $t->full_name,
                        'email' => $t->email,
                        'tier' => $t->tier,
                        'zone' => $t->zone_name,
                        'source' => $t->registration_source,
                        'status' => match (true) {
                            ! in_array($t->status, ['valid', 'used'], true) => 'cancelled',
                            $records->isNotEmpty() => 'attended',
                            default => 'no_show',
                        },
                        'first_check_in' => $records->isNotEmpty() ? $this->local($records->first()->checked_in_at, $tz)->format('M j, g:i A') : null,
                        'checked_in_at' => $records->isNotEmpty() ? $this->sortable($records->first()->checked_in_at) : null,
                        'sessions_attended' => $records->count(),
                        'method' => $records->isNotEmpty() ? ($records->contains('method', 'qr_scan') ? 'QR' : 'manual') : null,
                    ];
                })->sort(fn ($a, $b) => self::arrivalOrder($a, $b))->values()->all();
                $report['walk_in_names'] = $unticketed->whereNotNull('full_name')->map(fn ($w) => [
                    'name' => $w->full_name, 'at' => $this->local($w->recorded_at, $tz)->format('M j, g:i A'),
                    'checked_in_at' => $this->sortable($w->recorded_at),
                ])->values()->all();
            }

            return $report;
        });
    }

    /** @return list<array<string, mixed>> */
    private function tiers(Collection $tickets, Collection $byTicket): array
    {
        return $tickets->groupBy('ticket_type_id')->map(function ($group) use ($byTicket) {
            $came = $group->filter(fn ($t) => $byTicket->has($t->id))->count();

            return [
                'name' => $group->first()->tier,
                'zone' => $group->first()->zone_name,
                'sort' => (int) $group->first()->sort_order,
                'expected' => $group->count(),
                'attended' => $came,
                'no_shows' => $group->count() - $came,
                'rate' => round($came * 100 / $group->count(), 1),
            ];
        })->sortBy([['sort', 'asc'], ['name', 'asc']])->map(fn ($t) => collect($t)->except('sort')->all())->values()->all();
    }

    /** @return list<array<string, mixed>> */
    private function sources(Collection $tickets, Collection $byTicket): array
    {
        $labels = ['online' => 'Online registration', 'organizer' => 'Guest list', 'staff' => 'Added by staff', 'walk_in' => 'Door sales'];

        return $tickets->groupBy('registration_source')->map(fn ($group, $source) => [
            'source' => $source,
            'label' => $labels[$source] ?? $source,
            'expected' => $group->count(),
            'attended' => $group->filter(fn ($t) => $byTicket->has($t->id))->count(),
        ])->values()->all();
    }

    /**
     * Arrivals per time bucket (check-ins + walk-ins), in the event's time
     * zone, from the first to the last arrival. 15-minute buckets, widened
     * to whole hours for long events.
     *
     * @return array{bucket_minutes: int, buckets: list<array<string, mixed>>, peak: ?array<string, mixed>}
     */
    private function timeline(Collection $attendance, Collection $walkIns, string $tz): array
    {
        $events = $attendance->map(fn ($a) => ['at' => $this->local($a->checked_in_at, $tz), 'check_ins' => 1, 'walk_ins' => 0])
            ->concat($walkIns->map(fn ($w) => ['at' => $this->local($w->recorded_at, $tz), 'check_ins' => 0, 'walk_ins' => (int) $w->count]));

        if ($events->isEmpty()) {
            return ['bucket_minutes' => self::BUCKET_MINUTES, 'buckets' => [], 'peak' => null];
        }

        $first = $events->min('at');
        $last = $events->max('at');
        $minutes = self::BUCKET_MINUTES;
        if ($first->diffInMinutes($last) / $minutes > self::MAX_BUCKETS) {
            $minutes = 60;
        }

        $floor = fn (CarbonImmutable $t) => $t->setTime($t->hour, intdiv($t->minute, $minutes) * $minutes);
        $multiDay = $first->toDateString() !== $last->toDateString();

        // Each day with arrivals runs from its first to its last arrival (no empty days in between).
        $buckets = [];
        foreach ($events->groupBy(fn ($e) => $e['at']->toDateString())->sortKeys() as $day) {
            $counts = $day->groupBy(fn ($e) => $floor($e['at'])->format('H:i'));
            for ($t = $floor($day->min('at')); $t <= $day->max('at'); $t = $t->addMinutes($minutes)) {
                $group = $counts->get($t->format('H:i'), collect());
                $buckets[] = [
                    'label' => ($multiDay ? $t->format('M j ') : '').$t->format('g:i A'),
                    'starts_at' => $t->toIso8601String(),
                    'check_ins' => (int) $group->sum('check_ins'),
                    'walk_ins' => (int) $group->sum('walk_ins'),
                ];
            }
        }

        $peak = collect($buckets)->sortByDesc(fn ($b) => $b['check_ins'] + $b['walk_ins'])->first();

        return ['bucket_minutes' => $minutes, 'buckets' => $buckets, 'peak' => $peak];
    }

    /** @return array<string, string> */
    private function revenue(Event $event): array
    {
        $paid = DB::table('payments as p')->join('registrations as r', 'r.id', '=', 'p.registration_id')
            ->leftJoin('platform_fee_snapshots as f', 'f.payment_id', '=', 'p.id')
            ->where('r.event_id', $event->id)->where('p.status', 'paid')->whereNull('p.deleted_at')
            ->get(['p.id', 'p.amount', 'f.computed_amount as fee']);
        $refunds = DB::table('payment_refunds')->whereIn('payment_id', $paid->pluck('id'))
            ->selectRaw('coalesce(sum(amount), 0) as amount, coalesce(sum(fee_portion_refunded), 0) as fee_returned')->first();
        $door = (float) DB::table('walk_in_records as w')->join('tickets as t', 't.id', '=', 'w.linked_ticket_id')
            ->where('w.event_id', $event->id)->where('t.status', '!=', 'cancelled')->sum('t.purchased_price');

        $gross = (float) $paid->sum('amount');
        $fees = (float) $paid->sum('fee') - (float) $refunds->fee_returned;
        $net = $gross - $fees - (float) $refunds->amount;
        $money = fn (float $v) => number_format($v, 2, '.', '');

        return [
            'online_gross' => $money($gross),
            'platform_fee' => $money($fees),
            'refunds' => $money((float) $refunds->amount),
            'online_net' => $money($net),
            'door_cash' => $money($door),
            'total' => $money($net + $door),
        ];
    }

    /**
     * Adviser's order (2026-11): who arrived first is on top, then no-shows, then
     * cancelled tickets; names break ties.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    public static function arrivalOrder(array $a, array $b): int
    {
        $rank = fn ($r) => ['attended' => 0, 'no_show' => 1, 'cancelled' => 2][$r['status']] ?? 3;

        return [$rank($a), self::arrivalKey($a), mb_strtolower($a['name'] ?? '')]
            <=> [$rank($b), self::arrivalKey($b), mb_strtolower($b['name'] ?? '')];
    }

    /**
     * Reports shared before checked_in_at existed only have the shown time
     * ("Oct 17, 1:54 PM"); read that instead, so their PDFs sort the same way.
     *
     * @param  array<string, mixed>  $row
     */
    private static function arrivalKey(array $row): string
    {
        if (! empty($row['checked_in_at'])) {
            return (string) $row['checked_in_at'];
        }
        $shown = $row['first_check_in'] ?? null;
        $at = is_string($shown) ? CarbonImmutable::createFromFormat('!M j, g:i A', $shown) : false;

        return $at ? $at->format('m-d H:i') : '';
    }

    /** A time that sorts as text (UTC, microseconds). */
    private function sortable(string $at): string
    {
        return CarbonImmutable::parse($at)->utc()->format('Y-m-d H:i:s.u');
    }

    private function local(string $at, string $tz): CarbonImmutable
    {
        return CarbonImmutable::parse($at)->setTimezone($tz);
    }
}
