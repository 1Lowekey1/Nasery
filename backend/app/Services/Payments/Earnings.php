<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\AdminAlerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Where an organizer's ticket money goes (0043).
 *
 * Online ticket payments land in NaSeRy's PayMongo account. Of each sale:
 *   refunds go back to the buyers (through PayMongo, right away),
 *   NaSeRy keeps its platform fee (less fees returned on valid refunds),
 *   the rest is the organizer's share, which NaSeRy pays out.
 * Admins record each payout; "still to be paid" = share - payouts.
 * A refund after a payout can make it negative: NaSeRy then takes it off
 * the next payout.
 */
class Earnings
{
    public const METHODS = ['bank_transfer' => 'Bank transfer', 'gcash' => 'GCash', 'maya' => 'Maya', 'other' => 'Other'];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AdminAlerts $alerts,
    ) {}

    /**
     * The at-a-glance breakdown (every plan).
     *
     * @return array<string, mixed>
     */
    public function summary(string $organizationId): array
    {
        return $this->db->system('earnings.summary', function () use ($organizationId) {
            $money = $this->totals([$organizationId])[$organizationId] ?? $this->emptyTotals();
            $payouts = DB::table('organization_payouts')->where('organization_id', $organizationId)
                ->orderByDesc('paid_at')->orderByDesc('created_at')->limit(50)->get();

            return [
                ...$money,
                'payouts' => $payouts->map(fn ($p) => $this->presentPayout($p))->values(),
            ];
        });
    }

    /**
     * Paid plans: the breakdown per event and per month.
     *
     * @return array{events: list<array<string, mixed>>, months: list<array<string, mixed>>}
     */
    public function details(string $organizationId, int $months): array
    {
        return $this->db->system('earnings.details', function () use ($organizationId, $months) {
            $rows = $this->paymentRows($organizationId);
            $events = $rows->groupBy('event_id')->map(fn (Collection $g) => [
                'id' => $g->first()->event_id,
                'name' => $g->first()->event_name,
                'payments' => $g->count(),
                ...$this->sum($g),
            ])->sortByDesc('gross')->values()->all();

            $from = now('Asia/Manila')->startOfMonth()->subMonths($months - 1);
            $byMonth = $rows->groupBy(fn ($r) => CarbonImmutable::parse($r->paid_at)->setTimezone('Asia/Manila')->format('Y-m'));
            $series = collect(range(0, $months - 1))->map(function ($i) use ($from, $byMonth) {
                $key = $from->copy()->addMonths($i)->format('Y-m');

                return ['month' => $key, ...$this->sum($byMonth[$key] ?? collect())];
            })->all();

            return ['events' => $events, 'months' => $series];
        });
    }

    /** Paid plans: every paid ticket payment with its fee and refunds, as CSV. */
    public function csv(string $organizationId): string
    {
        $rows = $this->db->system('earnings.csv', fn () => $this->paymentRows($organizationId));
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\xEF\xBB\xBF"); // Excel-friendly UTF-8 (names like "Niño" stay readable)
        fputcsv($out, ['Paid at', 'Event', 'Buyer', 'Payment ID', 'Ticket sales', 'Refunded to buyer', 'NaSeRy fee', 'Your share']);
        foreach ($rows as $r) {
            $fee = (float) $r->fee - (float) $r->fee_returned;
            fputcsv($out, [
                CarbonImmutable::parse($r->paid_at)->setTimezone('Asia/Manila')->format('Y-m-d H:i'), $r->event_name, $r->buyer, $r->id,
                number_format((float) $r->amount, 2, '.', ''), number_format((float) $r->refunded, 2, '.', ''),
                number_format($fee, 2, '.', ''), number_format((float) $r->amount - (float) $r->refunded - $fee, 2, '.', ''),
            ]);
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    /**
     * Admin: every organizer with ticket money, and what NaSeRy still owes them.
     *
     * @return list<array<string, mixed>>
     */
    public function balances(): array
    {
        return $this->db->system('earnings.balances', function () {
            $ids = DB::table('payments as p')->join('registrations as r', 'r.id', '=', 'p.registration_id')->join('events as e', 'e.id', '=', 'r.event_id')
                ->where('p.status', 'paid')->distinct()->pluck('e.organization_id')
                ->merge(DB::table('organization_payouts')->distinct()->pluck('organization_id'))->unique()->values()->all();
            $totals = $this->totals($ids);
            $names = DB::table('organizations')->whereIn('id', $ids)->pluck('display_name', 'id');
            $last = DB::table('organization_payouts')->whereIn('organization_id', $ids)->whereNull('voided_at')
                ->groupBy('organization_id')->selectRaw('organization_id, max(paid_at) as at')->pluck('at', 'organization_id');

            return collect($ids)->map(fn ($id) => [
                'organization' => ['id' => $id, 'name' => $names[$id] ?? 'Organizer'],
                ...($totals[$id] ?? $this->emptyTotals()),
                'last_payout_at' => isset($last[$id]) ? CarbonImmutable::parse($last[$id])->toIso8601String() : null,
            ])->sortByDesc('owed')->values()->all();
        });
    }

    /** @return list<array<string, mixed>> the latest refunds returned to buyers, for the admin Payouts page */
    public function recentRefunds(int $limit = 30): array
    {
        return $this->db->system('earnings.refunds', fn () => DB::table('payment_refunds as pr')
            ->join('payments as p', 'p.id', '=', 'pr.payment_id')
            ->join('registrations as r', 'r.id', '=', 'p.registration_id')
            ->join('events as e', 'e.id', '=', 'r.event_id')
            ->join('organizations as o', 'o.id', '=', 'e.organization_id')
            ->orderByRaw('coalesce(pr.refunded_at, pr.created_at) desc')->limit($limit)
            ->get(['pr.id', 'pr.amount', 'pr.reason', DB::raw('coalesce(pr.refunded_at, pr.created_at) as refunded_at'), 'r.purchaser_name', 'e.name as event', 'o.display_name as organizer'])
            ->map(fn ($x) => [
                'id' => $x->id, 'amount' => (float) $x->amount, 'reason' => $x->reason, 'buyer' => $x->purchaser_name,
                'event' => $x->event, 'organizer' => $x->organizer,
                'refunded_at' => CarbonImmutable::parse($x->refunded_at)->toIso8601String(),
            ])->all());
    }

    /** @return list<array<string, mixed>> the organizer's payouts, for the admin page */
    public function payoutsFor(string $organizationId): array
    {
        return $this->db->system('earnings.payouts', fn () => DB::table('organization_payouts')->where('organization_id', $organizationId)
            ->orderByDesc('paid_at')->orderByDesc('created_at')->limit(100)->get()->map(fn ($p) => $this->presentPayout($p))->all());
    }

    /**
     * @param  array{amount: float|string, method: string, reference: string, note?: ?string, paid_at?: ?string}  $data
     * @return array<string, mixed>
     */
    public function record(User $admin, Organization $organization, array $data): array
    {
        $amount = round((float) $data['amount'], 2);
        $paidAt = isset($data['paid_at']) ? CarbonImmutable::parse($data['paid_at'], 'Asia/Manila') : now();

        if ($paidAt->isFuture()) {
            throw ValidationException::withMessages(['paid_at' => 'A payout can\'t be dated in the future.']);
        }

        $payout = $this->db->system('earnings.record', function () use ($admin, $organization, $data, $amount, $paidAt) {
            $owed = ($this->totals([$organization->id])[$organization->id] ?? $this->emptyTotals())['owed'];
            if ($amount > $owed + 0.001) {
                throw ValidationException::withMessages(['amount' => 'That is more than NaSeRy owes this organizer ('.number_format(max($owed, 0), 2).').']);
            }

            $id = (string) Str::uuid7();
            DB::table('organization_payouts')->insert([
                'id' => $id, 'organization_id' => $organization->id, 'amount' => number_format($amount, 2, '.', ''),
                'method' => $data['method'], 'reference' => trim($data['reference']), 'note' => filled($data['note'] ?? null) ? trim($data['note']) : null,
                'paid_at' => $paidAt->utc(), 'recorded_by' => $admin->id, 'created_at' => now(),
            ]);

            return DB::table('organization_payouts')->where('id', $id)->first();
        });

        $this->audit->record('payout.recorded', ['organization_payouts', $payout->id],
            new: ['amount' => $amount, 'method' => $data['method'], 'reference' => $payout->reference], organizationId: $organization->id);
        $this->alerts->payoutUpdate($organization->id, '₱'.number_format($amount, 2).' paid out to you',
            'NaSeRy sent your ticket share by '.self::METHODS[$data['method']].' (reference '.$payout->reference.'). See Earnings for the details.');

        return $this->presentPayout($payout);
    }

    public function void(User $admin, string $payoutId, string $reason): array
    {
        $payout = $this->db->system('earnings.void', function () use ($admin, $payoutId, $reason) {
            $row = DB::table('organization_payouts')->where('id', $payoutId)->lockForUpdate()->first()
                ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            if ($row->voided_at !== null) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'This payout was already voided.', 409);
            }
            DB::table('organization_payouts')->where('id', $payoutId)->update(['voided_at' => now(), 'voided_by' => $admin->id, 'void_reason' => trim($reason)]);

            return DB::table('organization_payouts')->where('id', $payoutId)->first();
        });

        $this->audit->record('payout.voided', ['organization_payouts', $payout->id], new: ['amount' => (float) $payout->amount], organizationId: $payout->organization_id, reason: $reason);
        $this->alerts->payoutUpdate($payout->organization_id, 'A payout record was corrected',
            'The ₱'.number_format((float) $payout->amount, 2).' payout (reference '.$payout->reference.') was voided: '.rtrim(trim($reason), '.').'. It counts as still to be paid again.');

        return $this->presentPayout($payout);
    }

    /**
     * @param  list<string>  $organizationIds
     * @return array<string, array<string, float>>
     */
    private function totals(array $organizationIds): array
    {
        if ($organizationIds === []) {
            return [];
        }

        $rows = DB::table('payments as p')->join('registrations as r', 'r.id', '=', 'p.registration_id')->join('events as e', 'e.id', '=', 'r.event_id')
            ->leftJoin('platform_fee_snapshots as f', 'f.payment_id', '=', 'p.id')
            ->whereIn('e.organization_id', $organizationIds)->where('p.status', 'paid')->whereNull('p.deleted_at')
            ->groupBy('e.organization_id')
            ->selectRaw('e.organization_id, sum(p.amount) as gross, coalesce(sum(f.computed_amount), 0) as fee,
                coalesce(sum((select sum(pr.amount) from payment_refunds pr where pr.payment_id = p.id)), 0) as refunded,
                coalesce(sum((select sum(pr.fee_portion_refunded) from payment_refunds pr where pr.payment_id = p.id)), 0) as fee_returned')
            ->get()->keyBy('organization_id');
        $paid = DB::table('organization_payouts')->whereIn('organization_id', $organizationIds)->whereNull('voided_at')
            ->groupBy('organization_id')->selectRaw('organization_id, sum(amount) as paid')->pluck('paid', 'organization_id');

        return collect($organizationIds)->mapWithKeys(function ($id) use ($rows, $paid) {
            $r = $rows[$id] ?? null;
            $gross = (float) ($r->gross ?? 0);
            $refunded = (float) ($r->refunded ?? 0);
            $fee = (float) ($r->fee ?? 0) - (float) ($r->fee_returned ?? 0);
            $share = $gross - $refunded - $fee;
            $out = (float) ($paid[$id] ?? 0);

            return [$id => [
                'gross' => round($gross, 2),
                'refunded' => round($refunded, 2),
                'platform_fee' => round($fee, 2),
                'share' => round($share, 2),
                'paid_out' => round($out, 2),
                'owed' => round($share - $out, 2),
            ]];
        })->all();
    }

    private function paymentRows(string $organizationId): Collection
    {
        return DB::table('payments as p')->join('registrations as r', 'r.id', '=', 'p.registration_id')->join('events as e', 'e.id', '=', 'r.event_id')
            ->leftJoin('platform_fee_snapshots as f', 'f.payment_id', '=', 'p.id')
            ->where('e.organization_id', $organizationId)->where('p.status', 'paid')->whereNull('p.deleted_at')
            ->orderByDesc('p.paid_at')->limit(5000)
            ->get(['p.id', 'p.amount', 'p.paid_at', 'r.purchaser_name as buyer', 'e.id as event_id', 'e.name as event_name',
                DB::raw('coalesce(f.computed_amount, 0) as fee'),
                DB::raw('(select coalesce(sum(pr.amount), 0) from payment_refunds pr where pr.payment_id = p.id) as refunded'),
                DB::raw('(select coalesce(sum(pr.fee_portion_refunded), 0) from payment_refunds pr where pr.payment_id = p.id) as fee_returned')]);
    }

    /** @return array{gross: float, refunded: float, platform_fee: float, share: float} */
    private function sum(Collection $rows): array
    {
        $gross = (float) $rows->sum('amount');
        $refunded = (float) $rows->sum('refunded');
        $fee = (float) $rows->sum('fee') - (float) $rows->sum('fee_returned');

        return ['gross' => round($gross, 2), 'refunded' => round($refunded, 2), 'platform_fee' => round($fee, 2), 'share' => round($gross - $refunded - $fee, 2)];
    }

    /** @return array<string, float> */
    private function emptyTotals(): array
    {
        return ['gross' => 0.0, 'refunded' => 0.0, 'platform_fee' => 0.0, 'share' => 0.0, 'paid_out' => 0.0, 'owed' => 0.0];
    }

    /** @return array<string, mixed> */
    private function presentPayout(object $p): array
    {
        return [
            'id' => $p->id,
            'amount' => (float) $p->amount,
            'method' => $p->method,
            'method_label' => self::METHODS[$p->method] ?? $p->method,
            'reference' => $p->reference,
            'note' => $p->note,
            'paid_at' => CarbonImmutable::parse($p->paid_at)->toIso8601String(),
            'voided' => $p->voided_at !== null,
            'void_reason' => $p->void_reason,
        ];
    }
}
