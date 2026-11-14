<?php

namespace App\Services\Admin;

use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Platform-wide numbers for NaSeRy admins (B17; paper: "monitor total
 * users, total events, and check overall system activity").
 *
 * Team rule (2026-09-25): admins see TOTALS for every organizer without a
 * grant (counts, sums, ratings); lists of an organizer's events, clients,
 * team or payments need the organizer's consent (AccessRequests +
 * GrantedView). The only event rows shown here are public events, which
 * anyone can already see on the marketplace.
 *
 * Money: "sales" = settled ticket payments (payments.status = paid);
 * "platform fee" = fee snapshots minus fee returned by refunds. Months are
 * Asia/Manila calendar months.
 */
class PlatformAnalytics
{
    private const TZ = 'Asia/Manila';

    public function __construct(private readonly DatabaseContext $db) {}

    /** @return array<string, mixed> */
    public function overview(): array
    {
        return $this->db->system('admin.overview', function () {
            $totals = $this->totals();

            return [
                'totals' => $totals,
                'attention' => [
                    'applications' => DB::table('organizations')->whereNull('deleted_at')->whereIn('approval_status', ['pending', 'under_review'])->count(),
                    'reports' => DB::table('reviews as r')->whereNull('r.deleted_at')->whereIn('r.status', ['published', 'disputed'])
                        ->whereExists(fn ($s) => $s->from('review_reports as rp')->whereColumn('rp.review_id', 'r.id')->whereIn('rp.status', ['open', 'in_review']))->count(),
                    'fee_claims' => DB::table('payment_refunds as pr')
                        ->where(fn ($q) => $q->where('pr.reason', 'like', 'Force majeure:%')->orWhere('pr.reason', 'like', 'Payment error:%'))
                        ->whereNotExists(fn ($s) => $s->from('moderation_cases as c')->whereColumn('c.subject_id', 'pr.id')->where('c.subject_type', 'payment_refund'))->count(),
                    'support_invites' => DB::table('admin_access_requests')->where('initiated_by', 'organizer')->where('status', 'pending')->where('expires_at', '>', now())->count(),
                ],
                'top_organizers' => $this->organizerTotals(null, 5, 'sales'),
                'recent_activity' => DB::table('audit_logs as a')->leftJoin('users as u', 'u.id', '=', 'a.actor_user_id')->leftJoin('organizations as o', 'o.id', '=', 'a.organization_id')
                    ->whereNotIn('a.action', ['user.profile_updated', 'user.password_changed', 'user.password_reset'])
                    ->orderByDesc('a.created_at')->limit(8)
                    ->get(['a.action', 'a.created_at', 'a.entity_type', 'u.first_name', 'u.last_name', 'u.account_type', 'o.display_name as organization'])
                    ->map(fn ($a) => [
                        'action' => $a->action,
                        'actor' => $a->first_name ? trim("{$a->first_name} {$a->last_name}") : 'System',
                        'actor_type' => $a->account_type,
                        'organization' => $a->organization,
                        'at' => CarbonImmutable::parse($a->created_at)->toIso8601String(),
                    ]),
            ];
        });
    }

    /**
     * What NaSeRy earns (admin Overview): recurring plan revenue (monthly and
     * yearly run rate, by plan), paying vs free organizers, and the last 12
     * months of money in from plans and from the ticket fee.
     *
     * @return array<string, mixed>
     */
    public function earnings(): array
    {
        return $this->db->system('admin.earnings', function () {
            $active = DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')->whereNull('s.deleted_at')
                ->whereIn('s.status', ['active', 'payment_failed', 'grace_period'])->where('p.price_monthly', '>', 0)
                ->get(['s.organization_id', 's.billing_cycle', 'p.slug', 'p.name', 'p.price_monthly', 'p.price_yearly']);
            // Monthly value of each paid subscription (a yearly plan counts as a twelfth a month).
            $monthly = fn ($s) => $s->billing_cycle === 'yearly' ? (float) $s->price_yearly / 12 : (float) $s->price_monthly;
            $plans = $active->groupBy('slug')->map(fn (Collection $g) => [
                'plan' => $g->first()->name,
                'organizations' => $g->pluck('organization_id')->unique()->count(),
                'monthly' => $g->where('billing_cycle', 'monthly')->count(),
                'yearly' => $g->where('billing_cycle', 'yearly')->count(),
                'mrr' => round($g->sum($monthly), 2),
            ])->sortByDesc('mrr')->values();
            $mrr = round($active->sum($monthly), 2);

            $listed = DB::table('organizations')->whereNull('deleted_at')->where('approval_status', 'approved')->count();
            $paying = $active->pluck('organization_id')->unique()->count();

            $from = now('Asia/Manila')->startOfMonth()->subMonths(11)->utc();
            $planMoney = (float) DB::table('payments')->where('status', 'paid')->whereNotNull('subscription_invoice_id')->where('paid_at', '>=', $from)->sum('amount');
            $byPlan = DB::table('payments as pay')->join('subscription_invoices as i', 'i.id', '=', 'pay.subscription_invoice_id')
                ->join('subscriptions as s', 's.id', '=', 'i.subscription_id')->join('plans as p', 'p.id', '=', 's.plan_id')
                ->where('pay.status', 'paid')->where('pay.paid_at', '>=', $from)->groupBy('p.name')->selectRaw('p.name, sum(pay.amount) as v')->pluck('v', 'name');
            $feeMoney = (float) DB::table('platform_fee_snapshots')->where('created_at', '>=', $from)->sum('computed_amount')
                - (float) DB::table('payment_refunds')->where('refunded_at', '>=', $from)->sum('fee_portion_refunded');
            $ticketSales = (float) DB::table('payments')->where('status', 'paid')->whereNotNull('registration_id')->where('paid_at', '>=', $from)->sum('amount');
            $fee = DB::table('platform_fee_configs')->where('effective_from', '<=', now())
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))->orderByDesc('effective_from')->first();

            return [
                'mrr' => $mrr,
                'arr' => round($mrr * 12, 2),
                'plans' => $plans->all(),
                'organizations' => ['listed' => $listed, 'paying' => $paying, 'free' => max(0, $listed - $paying)],
                'last_12_months' => [
                    'from' => $from->setTimezone('Asia/Manila')->toDateString(),
                    'plans' => round($planMoney, 2),
                    'plans_by_plan' => $byPlan->map(fn ($v) => round((float) $v, 2))->all(),
                    'ticket_fee' => round($feeMoney, 2),
                    'ticket_sales' => round($ticketSales, 2),
                    'total' => round($planMoney + $feeMoney, 2),
                ],
                'fee_percent' => $fee && $fee->is_enabled && $fee->percentage_rate !== null ? round((float) $fee->percentage_rate * 100, 2) : null,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function totals(): array
    {
        $users = DB::table('users')->whereNull('deleted_at')->selectRaw('account_type::text as t, count(*) as n')->groupBy('account_type')->pluck('n', 't');
        $orgs = DB::table('organizations')->whereNull('deleted_at')->selectRaw('approval_status::text as s, count(*) as n')->groupBy('approval_status')->pluck('n', 's');
        $events = DB::table('events')->whereNull('deleted_at')->selectRaw('status::text as s, count(*) as n')->groupBy('status')->pluck('n', 's');
        $rating = DB::table('public_reviews')->selectRaw('avg(rating) as avg, count(*) as n')->first();
        $sales = (float) DB::table('payments')->where('status', 'paid')->whereNotNull('registration_id')->sum('amount');
        $refunds = (float) DB::table('payment_refunds')->sum('amount');
        $fee = (float) DB::table('platform_fee_snapshots')->sum('computed_amount') - (float) DB::table('payment_refunds')->sum('fee_portion_refunded');
        $month = now('Asia/Manila')->startOfMonth()->utc();

        return [
            'users' => ['client' => (int) ($users['client'] ?? 0), 'business' => (int) ($users['business'] ?? 0), 'admin' => (int) ($users['admin'] ?? 0),
                'suspended' => DB::table('users')->whereNull('deleted_at')->where('status', 'suspended')->count()],
            'organizers' => collect(['pending', 'under_review', 'approved', 'rejected', 'suspended'])->mapWithKeys(fn ($s) => [$s => (int) ($orgs[$s] ?? 0)]),
            'events' => collect(['draft', 'published', 'ongoing', 'completed', 'cancelled'])->mapWithKeys(fn ($s) => [$s => (int) ($events[$s] ?? 0)])->put('total', (int) $events->sum()),
            'tickets_sold' => DB::table('tickets')->whereIn('status', ['valid', 'used'])->count(),
            'checked_in' => DB::table('attendance_records')->distinct()->count('ticket_id'),
            'sales' => round($sales, 2),
            'refunds' => round($refunds, 2),
            'platform_fee' => round($fee, 2),
            // B18: organizer plan payments.
            'subscriptions' => round((float) DB::table('payments')->where('status', 'paid')->whereNotNull('subscription_invoice_id')->sum('amount'), 2),
            'rating' => ['average' => $rating?->avg !== null ? round((float) $rating->avg, 2) : null, 'count' => (int) ($rating->n ?? 0)],
            // Quotations waiting for the client's decision (counts only: the amounts stay the parties').
            'quotations_pending' => DB::table('quotations')->where('status', 'submitted')->count(),
            'quotations' => DB::table('quotations')->selectRaw('status::text as s, count(*) as n')->groupBy('status')->pluck('n', 's')->map(fn ($n) => (int) $n),
            'this_month' => [
                'clients' => DB::table('users')->whereNull('deleted_at')->where('account_type', 'client')->where('created_at', '>=', $month)->count(),
                'organizers' => DB::table('organizations')->whereNull('deleted_at')->where('created_at', '>=', $month)->count(),
                'events' => DB::table('events')->whereNull('deleted_at')->where('created_at', '>=', $month)->count(),
                'quotations' => DB::table('quotations')->where('created_at', '>=', $month)->count(),
            ],
        ];
    }

    /**
     * Totals per organizer (no grant needed). Pass ids to limit, or null for
     * the top $limit by $sort.
     *
     * @param  list<string>|null  $ids
     * @return Collection<int, array<string, mixed>>
     */
    public function organizerTotals(?array $ids, int $limit = 200, string $sort = 'sales'): Collection
    {
        // $sort goes into the SQL as a column name, so only these are accepted.
        $sort = match ($sort) {
            'sales' => 's.sales', 'tickets' => 't.tickets', 'events' => 'e.events',
            'rating' => 'r.rating', 'reviews' => 'r.reviews', 'plans' => 'pl.plans',
            default => throw new \InvalidArgumentException("Unknown organizer sort: {$sort}"),
        };
        $sales = DB::table('payments as p')->join('registrations as rg', 'rg.id', '=', 'p.registration_id')->join('events as e', 'e.id', '=', 'rg.event_id')
            ->where('p.status', 'paid')->groupBy('e.organization_id')->selectRaw('e.organization_id, sum(p.amount) as sales');
        $tickets = DB::table('tickets as t')->join('events as e', 'e.id', '=', 't.event_id')->whereIn('t.status', ['valid', 'used'])
            ->groupBy('e.organization_id')->selectRaw('e.organization_id, count(*) as tickets');
        $events = DB::table('events')->whereNull('deleted_at')->groupBy('organization_id')
            ->selectRaw("organization_id, count(*) as events, count(*) filter (where status = 'completed') as completed");
        $ratings = DB::table('public_reviews')->groupBy('organization_id')->selectRaw('organization_id, avg(rating) as rating, count(*) as reviews');
        $members = DB::table('organization_members')->where('status', 'active')->whereNull('deleted_at')->groupBy('organization_id')->selectRaw('organization_id, count(*) as members');
        // Where the ticket money went: NaSeRy's fee (less fees returned on refunds) and refunds to buyers.
        $fees = DB::table('platform_fee_snapshots as f')->join('payments as p', 'p.id', '=', 'f.payment_id')->join('registrations as rg', 'rg.id', '=', 'p.registration_id')
            ->join('events as e', 'e.id', '=', 'rg.event_id')->groupBy('e.organization_id')->selectRaw('e.organization_id, sum(f.computed_amount) as fee');
        $refunds = DB::table('payment_refunds as f')->join('payments as p', 'p.id', '=', 'f.payment_id')->join('registrations as rg', 'rg.id', '=', 'p.registration_id')
            ->join('events as e', 'e.id', '=', 'rg.event_id')->groupBy('e.organization_id')
            ->selectRaw('e.organization_id, sum(f.amount) as refunded, sum(f.fee_portion_refunded) as fee_returned');
        // What the organizer paid NaSeRy for its plan (B18).
        $plans = DB::table('payments as p')->join('subscription_invoices as i', 'i.id', '=', 'p.subscription_invoice_id')->join('subscriptions as sb', 'sb.id', '=', 'i.subscription_id')
            ->where('p.status', 'paid')->groupBy('sb.organization_id')->selectRaw('sb.organization_id, sum(p.amount) as plans');

        return DB::table('organizations as o')
            ->leftJoinSub($sales, 's', 's.organization_id', '=', 'o.id')
            ->leftJoinSub($tickets, 't', 't.organization_id', '=', 'o.id')
            ->leftJoinSub($events, 'e', 'e.organization_id', '=', 'o.id')
            ->leftJoinSub($ratings, 'r', 'r.organization_id', '=', 'o.id')
            ->leftJoinSub($members, 'm', 'm.organization_id', '=', 'o.id')
            ->leftJoinSub($fees, 'f', 'f.organization_id', '=', 'o.id')
            ->leftJoinSub($refunds, 'rf', 'rf.organization_id', '=', 'o.id')
            ->leftJoinSub($plans, 'pl', 'pl.organization_id', '=', 'o.id')
            ->whereNull('o.deleted_at')
            ->when($ids !== null, fn ($q) => $q->whereIn('o.id', $ids), fn ($q) => $q->where('o.approval_status', 'approved')->orderByDesc(DB::raw("coalesce({$sort}, 0)"))->limit($limit))
            ->get(['o.id', 'o.display_name', 's.sales', 't.tickets', 'e.events', 'e.completed', 'r.rating', 'r.reviews', 'm.members',
                'f.fee', 'rf.refunded', 'rf.fee_returned', 'pl.plans'])
            ->map(fn ($o) => [
                'id' => $o->id,
                'name' => $o->display_name,
                'events' => (int) $o->events,
                'completed_events' => (int) $o->completed,
                'tickets_sold' => (int) $o->tickets,
                // Gross ticket sales: the buyers' money, not NaSeRy's.
                'sales' => round((float) $o->sales, 2),
                'refunded' => round((float) $o->refunded, 2),
                'platform_fee' => round((float) $o->fee - (float) $o->fee_returned, 2),
                'organizer_net' => round((float) $o->sales - (float) $o->refunded - ((float) $o->fee - (float) $o->fee_returned), 2),
                'plan_payments' => round((float) $o->plans, 2),
                'rating' => $o->rating !== null ? round((float) $o->rating, 2) : null,
                'reviews' => (int) $o->reviews,
                'members' => (int) $o->members,
            ]);
    }

    /**
     * Monthly trends for the last $months months.
     *
     * @return array<string, mixed>
     */
    public function trends(int $months): array
    {
        return $this->db->system('admin.trends', function () use ($months) {
            $start = CarbonImmutable::now(self::TZ)->startOfMonth()->subMonths($months - 1);
            $labels = collect(range(0, $months - 1))->map(fn ($i) => $start->addMonths($i)->format('Y-m'));
            $month = fn (string $col) => DB::raw("to_char(({$col}) at time zone '".self::TZ."', 'YYYY-MM') as m");
            $from = $start->utc();
            $series = function ($query) use ($labels) {
                $rows = $query->get()->pluck('v', 'm');

                return $labels->map(fn ($l) => (float) ($rows[$l] ?? 0))->values();
            };

            $users = DB::table('users')->whereNull('deleted_at')->where('created_at', '>=', $from)
                ->select([$month('created_at'), DB::raw('account_type::text as t'), DB::raw('count(*) as v')])->groupBy('m', 't')->get();

            return [
                'months' => $labels,
                'new_clients' => $labels->map(fn ($l) => (int) ($users->first(fn ($r) => $r->m === $l && $r->t === 'client')->v ?? 0)),
                'new_organizers' => $labels->map(fn ($l) => (int) ($users->first(fn ($r) => $r->m === $l && $r->t === 'business')->v ?? 0)),
                'events_created' => $series(DB::table('events')->whereNull('deleted_at')->where('created_at', '>=', $from)->select([$month('created_at'), DB::raw('count(*) as v')])->groupBy('m')),
                'tickets_sold' => $series(DB::table('tickets')->whereIn('status', ['valid', 'used'])->where('created_at', '>=', $from)->select([$month('created_at'), DB::raw('count(*) as v')])->groupBy('m')),
                'sales' => $series(DB::table('payments')->where('status', 'paid')->whereNotNull('registration_id')->where('paid_at', '>=', $from)->select([$month('paid_at'), DB::raw('sum(amount) as v')])->groupBy('m')),
                'platform_fee' => $series(DB::table('platform_fee_snapshots')->where('created_at', '>=', $from)->select([$month('created_at'), DB::raw('sum(computed_amount) as v')])->groupBy('m'))
                    ->zip($series(DB::table('payment_refunds')->where('refunded_at', '>=', $from)->select([$month('refunded_at'), DB::raw('sum(coalesce(fee_portion_refunded, 0)) as v')])->groupBy('m')))
                    ->map(fn ($pair) => round($pair[0] - $pair[1], 2)),
                'subscriptions' => $series(DB::table('payments')->where('status', 'paid')->whereNotNull('subscription_invoice_id')->where('paid_at', '>=', $from)->select([$month('paid_at'), DB::raw('sum(amount) as v')])->groupBy('m')),
                'inquiries' => $series(DB::table('inquiries')->where('created_at', '>=', $from)->whereNot('status', 'draft')->select([$month('created_at'), DB::raw('count(*) as v')])->groupBy('m')),
                'quotations_accepted' => $series(DB::table('quotations')->where('status', 'accepted')->where('decided_at', '>=', $from)->select([$month('decided_at'), DB::raw('count(*) as v')])->groupBy('m')),
            ];
        });
    }

    /**
     * Events page: counts for everything, rows only for public events and
     * for organizers who granted this admin access.
     *
     * @param  list<string>  $grantedOrgIds
     * @return array<string, mixed>
     */
    public function events(array $grantedOrgIds, ?string $status, ?string $search): array
    {
        return $this->db->system('admin.events', function () use ($grantedOrgIds, $status, $search) {
            $firstSession = DB::table('event_sessions')->whereNull('deleted_at')->groupBy('event_id')->selectRaw('event_id, min(starts_at) as starts_at, max(ends_at) as ends_at');
            $sold = DB::table('tickets')->whereIn('status', ['valid', 'used'])->groupBy('event_id')->selectRaw('event_id, count(*) as sold');
            $sales = DB::table('payments as p')->join('registrations as rg', 'rg.id', '=', 'p.registration_id')->where('p.status', 'paid')
                ->groupBy('rg.event_id')->selectRaw('rg.event_id, sum(p.amount) as sales');
            $rows = DB::table('events as e')->join('organizations as o', 'o.id', '=', 'e.organization_id')
                ->leftJoinSub($firstSession, 's', 's.event_id', '=', 'e.id')->leftJoinSub($sold, 't', 't.event_id', '=', 'e.id')->leftJoinSub($sales, 'ps', 'ps.event_id', '=', 'e.id')
                ->leftJoin('event_types as et', 'et.id', '=', 'e.event_type_id')
                ->whereNull('e.deleted_at')
                ->where(fn ($q) => $q->where(fn ($p) => $p->where('e.visibility', 'public')->whereNot('e.status', 'draft'))->orWhereIn('e.organization_id', $grantedOrgIds))
                ->when($status, fn ($q) => $q->where('e.status', $status))
                ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('e.name', 'ilike', '%'.addcslashes($search, '%_\\').'%')->orWhere('o.display_name', 'ilike', '%'.addcslashes($search, '%_\\').'%')))
                ->orderByRaw('s.starts_at desc nulls last')->limit(200)
                ->get(['e.id', 'e.name', 'e.status', 'e.visibility', 'e.overall_capacity', 'e.venue_name', 'o.id as org_id', 'o.display_name', 's.starts_at', 's.ends_at', 't.sold', 'ps.sales', 'et.name as event_type']);

            return [
                'counts' => $this->totals()['events'],
                'items' => $rows->map(fn ($e) => [
                    'id' => $e->id,
                    'name' => $e->name,
                    'status' => $e->status,
                    'visibility' => $e->visibility,
                    'event_type' => $e->event_type, // Wedding, Corporate, Birthday... (null when not set)
                    'organizer' => ['id' => $e->org_id, 'name' => $e->display_name],
                    'venue' => $e->venue_name,
                    'starts_at' => $e->starts_at ? CarbonImmutable::parse($e->starts_at)->toIso8601String() : null,
                    'ends_at' => $e->ends_at ? CarbonImmutable::parse($e->ends_at)->toIso8601String() : null,
                    'tickets_sold' => (int) $e->sold,
                    'ticket_sales' => round((float) $e->sales, 2),
                    'capacity' => $e->overall_capacity,
                    'via_grant' => $e->visibility !== 'public' || in_array($e->org_id, $grantedOrgIds, true),
                ])->values(),
            ];
        });
    }
}
