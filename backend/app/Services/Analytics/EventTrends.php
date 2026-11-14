<?php

namespace App\Services\Analytics;

use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Event trends across all of NaSeRy (advisor review, 2026-11): per month and
 * event type, how many event requests clients sent and how many events were
 * booked. Counts only, never which client or organizer, so organizers can see
 * what is in demand and admins see the same picture.
 *
 * NaSeRy's standard event types keep their names; an organizer's own custom
 * types count as "Other". The busiest types are shown, the rest folded into
 * "Other".
 */
class EventTrends
{
    private const TZ = 'Asia/Manila';

    /** At most this many named types (plus "Other"), so a chart stays readable. */
    private const MAX_TYPES = 5;

    public function __construct(private readonly DatabaseContext $db) {}

    /**
     * @return array{months: list<string>, types: list<array{name: string, requests: list<int>, events: list<int>, total: int}>, totals: array{requests: list<int>, events: list<int>}}
     */
    public function monthly(int $months = 6): array
    {
        return $this->db->system('analytics.event_trends', function () use ($months) {
            $start = CarbonImmutable::now(self::TZ)->startOfMonth()->subMonths($months - 1);
            $labels = collect(range(0, $months - 1))->map(fn ($i) => $start->addMonths($i)->format('Y-m'));
            $from = $start->utc();
            $month = fn (string $col) => DB::raw("to_char(({$col}) at time zone '".self::TZ."', 'YYYY-MM') as m");
            // Standard types by name; custom (organizer-owned) or missing types are "Other".
            $type = DB::raw("case when et.organization_id is null and et.name is not null then et.name else 'Other' end as t");

            $requests = DB::table('inquiries as i')->leftJoin('event_types as et', 'et.id', '=', 'i.event_type_id')
                ->whereNot('i.status', 'draft')->where('i.created_at', '>=', $from)
                ->select([$month('i.created_at'), $type, DB::raw('count(*) as v')])->groupBy('m', 't')->get();
            $events = DB::table('events as e')->leftJoin('event_types as et', 'et.id', '=', 'e.event_type_id')
                ->whereNull('e.deleted_at')->whereNot('e.status', 'cancelled')->where('e.created_at', '>=', $from)
                ->select([$month('e.created_at'), $type, DB::raw('count(*) as v')])->groupBy('m', 't')->get();

            $series = fn (Collection $rows, string $name) => $labels->map(fn ($l) => (int) $rows->filter(fn ($r) => $r->m === $l && $r->t === $name)->sum('v'))->values()->all();
            $names = $requests->merge($events)->groupBy('t')->map(fn ($rows) => (int) $rows->sum('v'))->sortDesc();
            $top = $names->keys()->reject(fn ($n) => $n === 'Other')->take(self::MAX_TYPES)->values();

            $types = $top->map(fn ($name) => [
                'name' => $name,
                'requests' => $series($requests, $name),
                'events' => $series($events, $name),
                'total' => (int) $names[$name],
            ])->all();

            // Everything else (custom types, "Other", smaller types) in one line.
            $rest = fn (Collection $rows) => $labels->map(fn ($l) => (int) $rows->filter(fn ($r) => $r->m === $l && ! $top->contains($r->t))->sum('v'))->values()->all();
            $other = ['name' => 'Other', 'requests' => $rest($requests), 'events' => $rest($events)];
            $other['total'] = array_sum($other['requests']) + array_sum($other['events']);
            if ($other['total'] > 0) {
                $types[] = $other;
            }

            $sum = fn (Collection $rows) => $labels->map(fn ($l) => (int) $rows->where('m', $l)->sum('v'))->values()->all();

            return [
                'months' => $labels->values()->all(),
                'types' => $types,
                'totals' => ['requests' => $sum($requests), 'events' => $sum($events)],
            ];
        });
    }

    /**
     * One organizer's own picture: the event types they handle most (events
     * booked in the period), with the requests they received for each. Their
     * custom types keep their names here; it's their data.
     *
     * @return array{from: string, types: list<array{name: string, events: int, requests: int, share: int}>, totals: array{events: int, requests: int}}
     */
    public function forOrganization(string $organizationId, int $months = 6): array
    {
        return $this->db->system('analytics.org_event_types', function () use ($organizationId, $months) {
            $start = CarbonImmutable::now(self::TZ)->startOfMonth()->subMonths($months - 1);
            $from = $start->utc();
            $type = DB::raw("coalesce(et.name, 'Other') as t");

            $events = DB::table('events as e')->leftJoin('event_types as et', 'et.id', '=', 'e.event_type_id')
                ->where('e.organization_id', $organizationId)->whereNull('e.deleted_at')->whereNot('e.status', 'cancelled')
                ->where('e.created_at', '>=', $from)
                ->select([$type, DB::raw('count(*) as v')])->groupBy('t')->pluck('v', 't')->map(fn ($v) => (int) $v);
            $requests = DB::table('inquiry_organizations as io')->join('inquiries as i', 'i.id', '=', 'io.inquiry_id')
                ->leftJoin('event_types as et', 'et.id', '=', 'i.event_type_id')
                ->where('io.organization_id', $organizationId)->whereNot('i.status', 'draft')->where('io.created_at', '>=', $from)
                ->select([$type, DB::raw('count(*) as v')])->groupBy('t')->pluck('v', 't')->map(fn ($v) => (int) $v);

            $totalEvents = $events->sum();
            // Most handled first; requests break ties (and rank types with requests but no events yet).
            $names = $events->keys()->merge($requests->keys())->unique()
                ->sortByDesc(fn ($n) => [$events[$n] ?? 0, $requests[$n] ?? 0])->values();
            $row = fn (string $n, int $e, int $r) => ['name' => $n, 'events' => $e, 'requests' => $r,
                'share' => $totalEvents > 0 ? (int) round($e / $totalEvents * 100) : 0];

            $top = $names->reject(fn ($n) => $n === 'Other')->take(self::MAX_TYPES + 1)->values();
            $types = $top->map(fn ($n) => $row($n, $events[$n] ?? 0, $requests[$n] ?? 0))->all();
            $restE = $events->reject(fn ($v, $n) => $top->contains($n))->sum();
            $restR = $requests->reject(fn ($v, $n) => $top->contains($n))->sum();
            if ($restE + $restR > 0) {
                $types[] = $row('Other', $restE, $restR);
            }

            return ['from' => $start->toDateString(), 'types' => $types, 'totals' => ['events' => $totalEvents, 'requests' => $requests->sum()]];
        });
    }
}
