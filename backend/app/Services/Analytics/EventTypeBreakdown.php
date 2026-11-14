<?php

namespace App\Services\Analytics;

use App\Models\Organization;
use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The detailed view behind Home's two event-type cards (Analytics → Event
 * Types), month by month for one period:
 *   - mine: per event type, the requests the organizer received, offers sent,
 *     won / lost, win rate and won value (same rules as Bid Performance), the
 *     events they booked, and whether the type is on their profile
 *   - market: per standard event type across NaSeRy, requests and booked
 *     events per month, the change against the period before, and how many
 *     listed organizers offer it (counts only, never who)
 */
class EventTypeBreakdown
{
    private const TZ = 'Asia/Manila';

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly OrganizerAnalytics $analytics,
    ) {}

    /**
     * @param  array{from: CarbonImmutable, to: CarbonImmutable, group: string, keys: list<string>, labels: list<string>}  $p  a monthly period
     * @return array<string, mixed>
     */
    public function forOrganization(Organization $org, array $p, bool $withBids, bool $withEvents): array
    {
        $bids = $withBids ? collect($this->analytics->bids($org, $p)['by_event_type'])->keyBy('event_type') : collect();

        return $this->db->system('analytics.event_type_breakdown', function () use ($org, $p, $bids, $withBids, $withEvents) {
            $from = $p['from']->utc();
            $months = count($p['keys']);
            $month = fn (string $col) => DB::raw("to_char(({$col}) at time zone '".self::TZ."', 'YYYY-MM') as m");
            $series = fn (Collection $rows, string $name) => collect($p['keys'])->map(fn ($k) => (int) $rows->filter(fn ($r) => $r->m === $k && $r->t === $name)->sum('v'))->values()->all();

            // ---- This organizer ----
            $events = $withEvents ? DB::table('events as e')->leftJoin('event_types as et', 'et.id', '=', 'e.event_type_id')
                ->where('e.organization_id', $org->id)->whereNull('e.deleted_at')->whereNot('e.status', 'cancelled')->where('e.created_at', '>=', $from)
                ->select([$month('e.created_at'), DB::raw("coalesce(et.name, 'Other') as t"), DB::raw('count(*) as v')])->groupBy('m', 't')->get() : collect();
            $tags = collect(json_decode((string) DB::table('organization_profiles')->where('organization_id', $org->id)->value('event_tags'), true) ?: [])
                ->map(fn ($t) => mb_strtolower(trim((string) $t)));

            $names = $bids->keys()->merge($events->pluck('t'))->merge($tags->isEmpty() ? [] : $this->standard()->filter(fn ($n) => $tags->contains(mb_strtolower($n))))->unique();
            $mine = $names->map(function ($name) use ($bids, $events, $series, $tags, $withBids, $withEvents) {
                $b = $bids->get($name, []);
                $row = ['name' => $name, 'on_profile' => $tags->contains(mb_strtolower($name))];
                // Members see only what their permissions cover: bids, events, or both.
                if ($withBids) {
                    $row += ['requests' => (int) ($b['inquiries'] ?? 0), 'offers' => (int) ($b['bids'] ?? 0), 'won' => (int) ($b['won'] ?? 0),
                        'lost' => (int) ($b['lost'] ?? 0), 'win_rate' => $b['win_rate'] ?? null, 'won_value' => $b['won_value'] ?? '0.00'];
                }
                if ($withEvents) {
                    $byMonth = $series($events, $name);
                    $row += ['events' => array_sum($byMonth), 'events_by_month' => $byMonth];
                }

                return $row;
            })->sortByDesc(fn ($r) => [$r['events'] ?? 0, $r['requests'] ?? 0])->values();

            // ---- NaSeRy-wide (standard types by name; custom or missing types are "Other") ----
            $type = DB::raw("case when et.organization_id is null and et.name is not null then et.name else 'Other' end as t");
            $prevFrom = $p['from']->subMonths($months)->utc();
            $requests = DB::table('inquiries as i')->leftJoin('event_types as et', 'et.id', '=', 'i.event_type_id')
                ->whereNot('i.status', 'draft')->where('i.created_at', '>=', $from)
                ->select([$month('i.created_at'), $type, DB::raw('count(*) as v')])->groupBy('m', 't')->get();
            $booked = DB::table('events as e')->leftJoin('event_types as et', 'et.id', '=', 'e.event_type_id')
                ->whereNull('e.deleted_at')->whereNot('e.status', 'cancelled')->where('e.created_at', '>=', $from)
                ->select([$month('e.created_at'), $type, DB::raw('count(*) as v')])->groupBy('m', 't')->get();
            $previous = DB::table('inquiries as i')->leftJoin('event_types as et', 'et.id', '=', 'i.event_type_id')
                ->whereNot('i.status', 'draft')->where('i.created_at', '>=', $prevFrom)->where('i.created_at', '<', $from)
                ->select([$type, DB::raw('count(*) as v')])->groupBy('t')->pluck('v', 't')->map(fn ($v) => (int) $v);
            // Listed organizers (approved, published) whose profile offers each type.
            $offering = DB::table('organization_profiles as pr')->join('organizations as o', 'o.id', '=', 'pr.organization_id')
                ->where('o.approval_status', 'approved')->where('pr.is_published', true)->pluck('pr.event_tags')
                ->flatMap(fn ($json) => collect(json_decode((string) $json, true) ?: [])->map(fn ($t) => mb_strtolower(trim((string) $t)))->unique())
                ->countBy();

            $allRequests = (int) $requests->sum('v');
            $market = $this->standard()->push('Other')->unique()->map(function ($name) use ($requests, $booked, $previous, $offering, $series, $allRequests) {
                $req = $series($requests, $name);
                $total = array_sum($req);
                $prev = (int) ($previous[$name] ?? 0);
                $orgs = $name === 'Other' ? null : (int) ($offering[mb_strtolower($name)] ?? 0);

                return [
                    'name' => $name,
                    'requests_by_month' => $req,
                    'booked_by_month' => $series($booked, $name),
                    'requests' => $total,
                    'booked' => array_sum($series($booked, $name)),
                    'share' => $allRequests > 0 ? round($total * 100 / $allRequests, 1) : 0,
                    'previous_requests' => $prev,
                    'change' => $prev > 0 ? round(($total - $prev) * 100 / $prev, 1) : null,
                    'organizers' => $orgs,
                    'requests_per_organizer' => $orgs ? round($total / $orgs, 1) : null,
                ];
            })->filter(fn ($r) => $r['requests'] + $r['booked'] + $r['previous_requests'] > 0 || $r['name'] !== 'Other')
                ->sortByDesc(fn ($r) => [$r['requests'], $r['booked']])->values();

            return [
                'mine' => $mine->all(),
                'market' => $market->all(),
                'totals' => [
                    'requests' => $allRequests,
                    'booked' => (int) $booked->sum('v'),
                    'previous_requests' => (int) $previous->sum(),
                ],
            ];
        });
    }

    /** @return Collection<int, string> NaSeRy's standard event type names */
    private function standard(): Collection
    {
        return DB::table('event_types')->whereNull('organization_id')->whereNull('deleted_at')->orderBy('name')->pluck('name');
    }
}
