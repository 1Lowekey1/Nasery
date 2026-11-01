<?php

namespace App\Services\Analytics;

use App\Models\Organization;
use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * B15 organizer analytics (paper: bid history, win/loss rates, inquiry
 * trends, event attendance averages, trends by month and quarter). All
 * figures come from NaSeRy's own records (paper limitation: activity
 * outside the platform isn't reflected). Runs in the system context scoped
 * to one organization; the controller decides which sections a member sees.
 *
 * Bids are counted per inquiry: the organizer's latest non-draft quotation
 * decides the outcome (revisions don't count twice).
 *   won      accepted
 *   lost     rejected (the client chose another organizer or declined)
 *   pending  submitted, waiting for the client
 *   withdrawn / cancelled   neither won nor lost
 * Win rate = won / (won + lost). Invitations never answered before the
 * inquiry closed count as "missed".
 */
class OrganizerAnalytics
{
    public const TZ = 'Asia/Manila';

    public function __construct(private readonly DatabaseContext $db) {}

    /**
     * @return array{from: CarbonImmutable, to: CarbonImmutable, group: string, keys: list<string>, labels: list<string>}
     */
    public function period(string $range, string $group): array
    {
        $to = now(self::TZ)->toImmutable();
        $from = match ($range) {
            '3m' => $to->subMonths(2)->startOfMonth(),
            '6m' => $to->subMonths(5)->startOfMonth(),
            'ytd' => $to->startOfYear(),
            '24m' => $to->subMonths(23)->startOfMonth(),
            default => $to->subMonths(11)->startOfMonth(),
        };
        if ($group === 'quarter') {
            $from = $from->startOfQuarter();
        }

        $keys = [];
        $labels = [];
        for ($t = $from; $t <= $to; $t = $group === 'quarter' ? $t->addQuarter() : $t->addMonth()) {
            $keys[] = $this->bucket($t, $group);
            $labels[] = $group === 'quarter' ? 'Q'.$t->quarter.' '.$t->year : $t->format('M Y');
        }

        return ['from' => $from, 'to' => $to, 'group' => $group, 'keys' => $keys, 'labels' => $labels];
    }

    /**
     * Bid performance: funnel, outcomes, response time, trends, by event type, history.
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, group: string, keys: list<string>, labels: list<string>}  $p
     * @return array<string, mixed>
     */
    public function bids(Organization $org, array $p): array
    {
        return $this->db->system('analytics.bids', function () use ($org, $p) {
            $invites = DB::table('inquiry_organizations as io')
                ->join('inquiries as i', 'i.id', '=', 'io.inquiry_id')
                ->leftJoin('event_types as et', 'et.id', '=', 'i.event_type_id')
                ->where('io.organization_id', $org->id)
                ->where('io.created_at', '>=', $p['from']->utc())
                ->get(['io.inquiry_id', 'io.status', 'io.created_at', 'i.status as inquiry_status', 'i.guest_count', 'i.requested_date', 'et.name as event_type']);

            $quotes = DB::table('quotations')->where('organization_id', $org->id)->whereIn('inquiry_id', $invites->pluck('inquiry_id'))
                ->where('status', '!=', 'draft')->whereNull('deleted_at')
                ->orderBy('created_at')->get(['id', 'inquiry_id', 'status', 'total_amount', 'submitted_at', 'decided_at', 'created_at'])
                ->groupBy('inquiry_id');
            // 0055: a won bid is worth the final agreed price once the organizer records one.
            $agreed = DB::table('events')->where('organization_id', $org->id)->whereIn('inquiry_id', $invites->pluck('inquiry_id'))
                ->whereNull('deleted_at')->whereNotNull('agreed_amount')->pluck('agreed_amount', 'inquiry_id');

            $rows = $invites->map(function ($inv) use ($quotes, $agreed) {
                $history = $quotes->get($inv->inquiry_id, collect());
                $final = $history->last();
                $firstSent = $history->pluck('submitted_at')->filter()->min();
                $outcome = match (true) {
                    $final === null && in_array($inv->status, ['declined'], true) => 'declined',
                    $final === null && in_array($inv->inquiry_status, ['submitted', 'receiving_bids'], true) => 'open',
                    $final === null => 'missed',
                    $final->status === 'accepted' => 'won',
                    $final->status === 'rejected' => 'lost',
                    $final->status === 'submitted' => 'pending',
                    default => $final->status, // withdrawn, cancelled
                };

                return (object) [
                    'inquiry_id' => $inv->inquiry_id,
                    'invited_at' => $this->local($inv->created_at),
                    'first_sent_at' => $firstSent ? $this->local($firstSent) : null,
                    'decided_at' => $final?->decided_at ? $this->local($final->decided_at) : null,
                    'outcome' => $outcome,
                    'amount' => $outcome === 'won' && isset($agreed[$inv->inquiry_id]) ? $agreed[$inv->inquiry_id] : $final?->total_amount,
                    'offer_amount' => $final?->total_amount,
                    'event_type' => $inv->event_type ?? 'Other',
                    'guest_count' => $inv->guest_count,
                    'requested_date' => $inv->requested_date,
                ];
            });

            $won = $rows->where('outcome', 'won');
            $lost = $rows->where('outcome', 'lost');
            $sent = $rows->whereNotNull('first_sent_at');
            $hours = $sent->map(fn ($r) => $r->invited_at->diffInMinutes($r->first_sent_at) / 60)->sort()->values();

            return [
                'totals' => [
                    'inquiries' => $rows->count(),
                    'open' => $rows->where('outcome', 'open')->count(),
                    'bids' => $sent->count(),
                    'won' => $won->count(),
                    'lost' => $lost->count(),
                    'pending' => $rows->where('outcome', 'pending')->count(),
                    'declined' => $rows->where('outcome', 'declined')->count(),
                    'missed' => $rows->where('outcome', 'missed')->count(),
                    'win_rate' => $this->rate($won->count(), $won->count() + $lost->count()),
                    'response_rate' => $this->rate($sent->count(), $rows->count()),
                    'median_response_hours' => $hours->isEmpty() ? null : round((float) $hours[intdiv($hours->count(), 2)], 1),
                    'won_value' => $this->money($won->sum(fn ($r) => (float) $r->amount)),
                    // What the bids said (offers as sent), so a later price change doesn't rewrite it.
                    'average_bid' => $sent->count() ? $this->money($sent->avg(fn ($r) => (float) $r->offer_amount)) : null,
                ],
                'trend' => collect($p['keys'])->map(fn ($key, $i) => [
                    'key' => $key,
                    'label' => $p['labels'][$i],
                    'inquiries' => $rows->filter(fn ($r) => $this->bucket($r->invited_at, $p['group']) === $key)->count(),
                    'bids' => $sent->filter(fn ($r) => $this->bucket($r->first_sent_at, $p['group']) === $key)->count(),
                    'won' => $won->filter(fn ($r) => $r->decided_at && $this->bucket($r->decided_at, $p['group']) === $key)->count(),
                    'lost' => $lost->filter(fn ($r) => $r->decided_at && $this->bucket($r->decided_at, $p['group']) === $key)->count(),
                    'won_value' => $this->money($won->filter(fn ($r) => $r->decided_at && $this->bucket($r->decided_at, $p['group']) === $key)->sum(fn ($r) => (float) $r->amount)),
                ])->values()->all(),
                'by_event_type' => $rows->groupBy('event_type')->map(fn ($g, $type) => [
                    'event_type' => $type,
                    'inquiries' => $g->count(),
                    'bids' => $g->whereNotNull('first_sent_at')->count(),
                    'won' => $g->where('outcome', 'won')->count(),
                    'win_rate' => $this->rate($g->where('outcome', 'won')->count(), $g->whereIn('outcome', ['won', 'lost'])->count()),
                ])->sortByDesc('inquiries')->values()->all(),
                'history' => $rows->sortByDesc('invited_at')->take(25)->map(fn ($r) => [
                    'inquiry_id' => $r->inquiry_id,
                    'event_type' => $r->event_type,
                    'guest_count' => $r->guest_count,
                    'requested_date' => $r->requested_date,
                    'invited_at' => $r->invited_at->toIso8601String(),
                    'first_sent_at' => $r->first_sent_at?->toIso8601String(),
                    'amount' => $r->amount,
                    'outcome' => $r->outcome,
                ])->values()->all(),
            ];
        });
    }

    /**
     * Event performance for events held in the period (by their first session).
     *
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, group: string, keys: list<string>, labels: list<string>}  $p
     * @return array<string, mixed>
     */
    public function events(Organization $org, array $p, bool $withRevenue): array
    {
        return $this->db->system('analytics.events', function () use ($org, $p, $withRevenue) {
            $events = DB::table('events as e')
                ->joinSub(DB::table('event_sessions')->whereNull('deleted_at')->where('status', '!=', 'cancelled')
                    ->groupBy('event_id')->selectRaw('event_id, min(starts_at) as starts_at'), 's', 's.event_id', '=', 'e.id')
                ->where('e.organization_id', $org->id)->whereNull('e.deleted_at')
                ->whereIn('e.status', ['published', 'ongoing', 'completed'])
                ->whereBetween('s.starts_at', [$p['from']->utc(), $p['to']->utc()])
                ->orderByDesc('s.starts_at')
                ->get(['e.id', 'e.name', 'e.status', 'e.overall_capacity', 's.starts_at']);
            $ids = $events->pluck('id');

            $tickets = DB::table('tickets')->whereIn('event_id', $ids)->whereNull('deleted_at')->whereIn('status', ['valid', 'used'])
                ->groupBy('event_id')->selectRaw('event_id, count(*) as expected')->pluck('expected', 'event_id');
            $attended = DB::table('attendance_records')->whereIn('event_id', $ids)
                ->groupBy('event_id')->selectRaw('event_id, count(distinct ticket_id) as attended')->pluck('attended', 'event_id');
            $walkIns = DB::table('walk_in_records')->whereIn('event_id', $ids)->whereNull('linked_ticket_id')
                ->groupBy('event_id')->selectRaw('event_id, sum(count) as n')->pluck('n', 'event_id');
            $revenue = $withRevenue ? $this->revenueByEvent($ids) : collect();

            $rows = $events->map(function ($e) use ($tickets, $attended, $walkIns, $revenue, $withRevenue) {
                $expected = (int) ($tickets[$e->id] ?? 0);
                $came = (int) ($attended[$e->id] ?? 0);
                $walk = (int) ($walkIns[$e->id] ?? 0);

                return [
                    'id' => $e->id,
                    'name' => $e->name,
                    'status' => $e->status,
                    'date' => $this->local($e->starts_at)->toIso8601String(),
                    'expected' => $expected,
                    'attended' => $came,
                    'no_shows' => $e->status === 'completed' ? $expected - $came : null,
                    'attendance_rate' => $this->rate($came, $expected),
                    'walk_ins' => $walk,
                    'people' => $came + $walk,
                    'capacity' => $e->overall_capacity,
                    'capacity_fill' => $e->overall_capacity ? $this->rate($came + $walk, (int) $e->overall_capacity) : null,
                ] + ($withRevenue ? ['revenue' => $this->money((float) ($revenue[$e->id] ?? 0))] : []);
            });

            $completed = $rows->where('status', 'completed');
            $sumExpected = $completed->sum('expected');

            return [
                'totals' => [
                    'events' => $rows->count(),
                    'completed' => $completed->count(),
                    'upcoming' => $rows->where('status', '!=', 'completed')->count(),
                    'attendees' => $completed->sum('people'),
                    'average_attendance_rate' => $this->rate($completed->sum('attended'), $sumExpected),
                    'no_show_rate' => $this->rate($completed->sum('no_shows'), $sumExpected),
                    'walk_in_share' => $this->rate($completed->sum('walk_ins'), $completed->sum('people')),
                    'average_capacity_fill' => $completed->whereNotNull('capacity_fill')->isEmpty() ? null : round($completed->whereNotNull('capacity_fill')->avg('capacity_fill'), 1),
                ] + ($withRevenue ? ['revenue' => $this->money($rows->sum(fn ($r) => (float) $r['revenue']))] : []),
                'trend' => collect($p['keys'])->map(function ($key, $i) use ($completed, $p, $withRevenue, $rows) {
                    $inBucket = $completed->filter(fn ($r) => $this->bucket(CarbonImmutable::parse($r['date']), $p['group']) === $key);
                    $expected = $inBucket->sum('expected');

                    return [
                        'key' => $key,
                        'label' => $p['labels'][$i],
                        'events' => $inBucket->count(),
                        'attendees' => $inBucket->sum('people'),
                        'attendance_rate' => $this->rate($inBucket->sum('attended'), $expected),
                        'no_show_rate' => $this->rate($inBucket->sum('no_shows'), $expected),
                    ] + ($withRevenue ? ['revenue' => $this->money($rows->filter(fn ($r) => $this->bucket(CarbonImmutable::parse($r['date']), $p['group']) === $key)
                        ->sum(fn ($r) => (float) $r['revenue']))] : []);
                })->values()->all(),
                'events' => $rows->values()->all(),
            ];
        });
    }

    /** Online net (after fee and refunds) + door cash, per event. */
    private function revenueByEvent(Collection $ids): Collection
    {
        $online = DB::table('payments as p')->join('registrations as r', 'r.id', '=', 'p.registration_id')
            ->leftJoin('platform_fee_snapshots as f', 'f.payment_id', '=', 'p.id')
            ->whereIn('r.event_id', $ids)->where('p.status', 'paid')->whereNull('p.deleted_at')
            ->groupBy('r.event_id')
            ->selectRaw('r.event_id, sum(p.amount) - coalesce(sum(f.computed_amount), 0)
                - coalesce(sum((select coalesce(sum(pr.amount), 0) - coalesce(sum(pr.fee_portion_refunded), 0) from payment_refunds pr where pr.payment_id = p.id)), 0) as net')
            ->pluck('net', 'event_id');
        $door = DB::table('walk_in_records as w')->join('tickets as t', 't.id', '=', 'w.linked_ticket_id')
            ->whereIn('w.event_id', $ids)->where('t.status', '!=', 'cancelled')
            ->groupBy('w.event_id')->selectRaw('w.event_id, sum(t.purchased_price) as cash')->pluck('cash', 'event_id');

        return $ids->mapWithKeys(fn ($id) => [$id => (float) ($online[$id] ?? 0) + (float) ($door[$id] ?? 0)]);
    }

    private function bucket(CarbonImmutable $t, string $group): string
    {
        $t = $t->setTimezone(self::TZ);

        return $group === 'quarter' ? $t->year.'-Q'.$t->quarter : $t->format('Y-m');
    }

    private function local(string $at): CarbonImmutable
    {
        return CarbonImmutable::parse($at)->setTimezone(self::TZ);
    }

    private function rate(int|float $part, int|float $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    private function money(float $v): string
    {
        return number_format($v, 2, '.', '');
    }
}
