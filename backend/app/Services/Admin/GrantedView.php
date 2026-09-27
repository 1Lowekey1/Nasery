<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The read-only look inside an organization that an approved access grant
 * allows (B17): its events, clients, team, payments/refunds and reviews.
 * Nothing here writes. Without a live grant (AccessRequests::activeGrant)
 * the admin gets 403 and only the totals from PlatformAnalytics.
 *
 * Payments, refunds and reviews have no grant-aware RLS policy, so the view
 * reads in the system context after the grant check; every opening is
 * audited so the organization can see when its workspace was viewed.
 */
class GrantedView
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AccessRequests $access,
        private readonly AuditLogger $audit,
    ) {}

    /** @return array<string, mixed> */
    public function show(User $admin, Organization $org): array
    {
        $grant = $this->access->activeGrant($admin, $org->id)
            ?? throw new ApiException(ErrorCode::Forbidden, 'This organizer has not given you access. Request access and wait for their approval.', 403);

        $this->audit->record('admin_access.viewed', ['organizations', $org->id], organizationId: $org->id, reason: $grant->reason);

        return $this->db->system('admin.granted_view', fn () => [
            'grant' => ['id' => $grant->id, 'expires_at' => CarbonImmutable::parse($grant->expires_at)->toIso8601String(), 'reason' => $grant->reason],
            'events' => $this->events($org->id),
            'clients' => $this->clients($org->id),
            'team' => $this->team($org->id),
            'payments' => $this->payments($org->id),
            'reviews' => $this->reviews($org->id),
        ]);
    }

    private function events(string $orgId)
    {
        $sessions = DB::table('event_sessions')->whereNull('deleted_at')->groupBy('event_id')->selectRaw('event_id, min(starts_at) as starts_at');
        $sold = DB::table('tickets')->whereIn('status', ['valid', 'used'])->groupBy('event_id')->selectRaw('event_id, count(*) as sold');
        $sales = DB::table('payments as p')->join('registrations as rg', 'rg.id', '=', 'p.registration_id')->where('p.status', 'paid')
            ->groupBy('rg.event_id')->selectRaw('rg.event_id, sum(p.amount) as sales');

        return DB::table('events as e')
            ->leftJoinSub($sessions, 's', 's.event_id', '=', 'e.id')->leftJoinSub($sold, 't', 't.event_id', '=', 'e.id')->leftJoinSub($sales, 'm', 'm.event_id', '=', 'e.id')
            ->leftJoin('inquiries as i', 'i.id', '=', 'e.inquiry_id')->leftJoin('users as c', 'c.id', '=', 'i.client_user_id')
            ->where('e.organization_id', $orgId)->whereNull('e.deleted_at')
            ->orderByRaw('s.starts_at desc nulls last')->limit(100)
            ->get(['e.id', 'e.name', 'e.status', 'e.visibility', 'e.venue_name', 'e.overall_capacity', 's.starts_at', 't.sold', 'm.sales', 'c.first_name', 'c.last_name'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'status' => $e->status,
                'visibility' => $e->visibility,
                'client' => $e->first_name ? trim("{$e->first_name} {$e->last_name}") : null,
                'venue' => $e->venue_name,
                'starts_at' => $e->starts_at ? CarbonImmutable::parse($e->starts_at)->toIso8601String() : null,
                'tickets_sold' => (int) $e->sold,
                'capacity' => $e->overall_capacity,
                'sales' => round((float) $e->sales, 2),
            ]);
    }

    /** Clients who booked this organizer (accepted quotations). */
    private function clients(string $orgId)
    {
        return DB::table('quotations as q')->join('inquiries as i', 'i.id', '=', 'q.inquiry_id')->join('users as u', 'u.id', '=', 'i.client_user_id')
            ->where('q.organization_id', $orgId)->where('q.status', 'accepted')
            ->groupBy('u.id', 'u.first_name', 'u.last_name')
            ->orderByRaw('max(q.decided_at) desc')
            ->get(['u.first_name', 'u.last_name', DB::raw('count(*) as bookings'), DB::raw('max(q.decided_at) as last_booked')])
            ->map(fn ($c) => ['name' => trim("{$c->first_name} {$c->last_name}"), 'bookings' => (int) $c->bookings,
                'last_booked' => $c->last_booked ? CarbonImmutable::parse($c->last_booked)->toIso8601String() : null]);
    }

    private function team(string $orgId)
    {
        return DB::table('organization_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('organization_roles as r', 'r.id', '=', 'm.organization_role_id')
            ->where('m.organization_id', $orgId)->whereNull('m.deleted_at')
            ->orderByDesc('m.is_owner')->orderBy('u.first_name')
            ->get(['u.first_name', 'u.last_name', 'u.email', 'm.status', 'm.is_owner', 'm.is_primary_owner', 'r.name as role', 'm.created_at'])
            ->map(fn ($m) => [
                'name' => trim("{$m->first_name} {$m->last_name}"),
                'email' => $m->email,
                'role' => $m->is_primary_owner ? 'Primary owner' : ($m->is_owner ? 'Owner' : ($m->role ?? 'Member')),
                'status' => $m->status,
                'joined' => CarbonImmutable::parse($m->created_at)->toIso8601String(),
            ]);
    }

    /** @return array<string, mixed> */
    private function payments(string $orgId): array
    {
        $base = fn () => DB::table('payments as p')->join('registrations as rg', 'rg.id', '=', 'p.registration_id')
            ->join('events as e', 'e.id', '=', 'rg.event_id')->where('e.organization_id', $orgId);
        $refunds = DB::table('payment_refunds as pr')->joinSub($base()->select('p.id'), 'x', 'x.id', '=', 'pr.payment_id');

        return [
            'paid_count' => $base()->where('p.status', 'paid')->count(),
            'sales' => round((float) $base()->where('p.status', 'paid')->sum('p.amount'), 2),
            'failed_count' => $base()->where('p.status', 'failed')->count(),
            'fee' => round((float) DB::table('platform_fee_snapshots as f')->joinSub($base()->select('p.id'), 'x', 'x.id', '=', 'f.payment_id')->sum('f.computed_amount'), 2),
            'refunded' => round((float) (clone $refunds)->sum('pr.amount'), 2),
            'recent_refunds' => (clone $refunds)->join('payments as p2', 'p2.id', '=', 'pr.payment_id')->join('registrations as r2', 'r2.id', '=', 'p2.registration_id')
                ->join('events as e2', 'e2.id', '=', 'r2.event_id')->orderByDesc('pr.refunded_at')->limit(10)
                ->get(['pr.amount', 'pr.fee_portion_refunded', 'pr.reason', 'pr.refunded_at', 'e2.name as event'])
                ->map(fn ($r) => ['amount' => (float) $r->amount, 'fee_returned' => $r->fee_portion_refunded !== null ? (float) $r->fee_portion_refunded : null,
                    'reason' => $r->reason, 'event' => $r->event, 'at' => CarbonImmutable::parse($r->refunded_at)->toIso8601String()]),
        ];
    }

    private function reviews(string $orgId)
    {
        return DB::table('reviews as r')->join('events as e', 'e.id', '=', 'r.event_id')->join('users as u', 'u.id', '=', 'r.client_user_id')
            ->where('r.organization_id', $orgId)->whereNull('r.deleted_at')->orderByDesc('r.created_at')->limit(50)
            ->get(['r.id', 'r.rating', 'r.written_review', 'r.status', 'r.created_at', 'e.name as event', 'u.first_name', 'u.last_name'])
            ->map(fn ($r) => ['id' => $r->id, 'rating' => (int) $r->rating, 'text' => $r->written_review, 'status' => $r->status, 'event' => $r->event,
                'reviewer' => trim($r->first_name.' '.mb_substr((string) $r->last_name, 0, 1).'.'), 'at' => CarbonImmutable::parse($r->created_at)->toIso8601String()]);
    }
}
