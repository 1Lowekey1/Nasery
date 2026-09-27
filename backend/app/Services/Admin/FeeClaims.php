<?php

namespace App\Services\Admin;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Notifications\AdminAlerts;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Review of refund "fee claims" (B10 open item). Organizers pick the
 * refund category themselves; force majeure and payment error hand the
 * platform fee back (user policy), so NaSeRy checks those claims here.
 *
 *  - accepted: the reason holds, nothing changes
 *  - flagged:  the reason looks like negligence; the owners are told and it
 *              stays on record for decisions such as suspension
 *
 * No money moves (capstone demo mode, and 0014 forbids editing refunds).
 * Each review is a moderation case on the refund (subject_type
 * payment_refund) and an audit entry. Refunds carry the platform's own fee,
 * which is why admins see these rows without a workspace grant: only the
 * refund line, never buyers or other payments.
 */
class FeeClaims
{
    /** Categories that return the fee, as stored at the start of payment_refunds.reason. */
    private const PREFIXES = ['Force majeure:', 'Payment error:'];

    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly AdminAlerts $alerts,
    ) {}

    /** @return array<string, mixed> */
    public function list(string $status): array
    {
        return $this->db->system('fee_claims.list', function () use ($status) {
            $rows = $this->query()
                ->when($status === 'pending', fn ($q) => $q->whereNull('c.id'))
                ->when($status === 'accepted', fn ($q) => $q->where('c.action_taken', 'claim_accepted'))
                ->when($status === 'flagged', fn ($q) => $q->where('c.action_taken', 'claim_flagged'))
                ->orderByDesc('pr.refunded_at')->limit(100)->get();

            return [
                'counts' => [
                    'pending' => $this->query()->whereNull('c.id')->count(),
                    'flagged' => $this->query()->where('c.action_taken', 'claim_flagged')->count(),
                ],
                'items' => $rows->map(fn ($r) => $this->present($r))->values(),
            ];
        });
    }

    public function review(User $admin, string $refundId, bool $accept, ?string $note): array
    {
        $row = $this->db->system('fee_claims.find', fn () => $this->query()->where('pr.id', $refundId)->first())
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        if ($row->case_id !== null) {
            throw new ApiException(ErrorCode::Conflict, 'This claim was already reviewed.', 409);
        }

        $this->db->system('fee_claims.review', fn () => DB::table('moderation_cases')->insert([
            'id' => (string) Str::uuid7(), 'subject_type' => 'payment_refund', 'subject_id' => $refundId, 'opened_by' => $admin->id,
            'status' => 'resolved', 'resolution' => $note, 'action_taken' => $accept ? 'claim_accepted' : 'claim_flagged',
            'resolved_at' => now(), 'created_at' => now(),
        ]));

        $this->audit->record($accept ? 'refund.claim_accepted' : 'refund.claim_flagged', ['payment_refunds', $refundId],
            new: ['fee_returned' => (float) $row->fee_portion_refunded], organizationId: $row->organization_id, reason: $note);
        $this->alerts->feeClaimReviewed($row->organization_id, $row->event_name, number_format((float) $row->amount, 2), $accept, $note);

        return $this->present($this->db->system('fee_claims.reload', fn () => $this->query()->where('pr.id', $refundId)->first()));
    }

    private function query()
    {
        return DB::table('payment_refunds as pr')
            ->join('payments as p', 'p.id', '=', 'pr.payment_id')
            ->join('registrations as rg', 'rg.id', '=', 'p.registration_id')
            ->join('events as e', 'e.id', '=', 'rg.event_id')
            ->join('organizations as o', 'o.id', '=', 'e.organization_id')
            ->join('users as u', 'u.id', '=', 'pr.recorded_by')
            ->leftJoin('moderation_cases as c', fn ($j) => $j->on('c.subject_id', '=', 'pr.id')->where('c.subject_type', 'payment_refund'))
            ->leftJoin('users as cu', 'cu.id', '=', 'c.opened_by')
            ->where(fn ($q) => collect(self::PREFIXES)->each(fn ($p) => $q->orWhere('pr.reason', 'like', $p.'%')))
            ->select(['pr.id', 'pr.amount', 'pr.fee_portion_refunded', 'pr.reason', 'pr.refunded_at', 'p.amount as payment_amount',
                'e.id as event_id', 'e.name as event_name', 'e.status as event_status', 'o.id as organization_id', 'o.display_name as organizer',
                'u.first_name', 'u.last_name', 'c.id as case_id', 'c.action_taken', 'c.resolution', 'c.resolved_at', 'cu.first_name as cu_first', 'cu.last_name as cu_last']);
    }

    /** @return array<string, mixed> */
    private function present(object $r): array
    {
        [$category, $detail] = array_pad(explode(':', $r->reason, 2), 2, '');

        return [
            'id' => $r->id,
            'category' => trim($category),
            'reason' => trim($detail),
            'amount' => (float) $r->amount,
            'payment_amount' => (float) $r->payment_amount,
            'fee_returned' => $r->fee_portion_refunded !== null ? (float) $r->fee_portion_refunded : null,
            'refunded_at' => CarbonImmutable::parse($r->refunded_at)->toIso8601String(),
            'recorded_by' => trim("{$r->first_name} {$r->last_name}"),
            'event' => ['id' => $r->event_id, 'name' => $r->event_name, 'status' => $r->event_status],
            'organizer' => ['id' => $r->organization_id, 'name' => $r->organizer],
            'review' => $r->case_id ? [
                'outcome' => $r->action_taken === 'claim_accepted' ? 'accepted' : 'flagged',
                'note' => $r->resolution,
                'by' => trim("{$r->cu_first} {$r->cu_last}"),
                'at' => CarbonImmutable::parse($r->resolved_at)->toIso8601String(),
            ] : null,
        ];
    }
}
