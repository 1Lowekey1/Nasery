<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\Payments\Payments;
use App\Services\Payments\RefundReason;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * An event's payments for the organizer (finance.view): gross, platform fee,
 * net, refunds. Refunds are an explicit, audited action (0014).
 */
class OrganizerPaymentController extends Controller
{
    public function __construct(
        private readonly Payments $payments,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, Organization $organization, Event $event): JsonResponse
    {
        $this->check($request, $organization, $event);

        $rows = DB::table('payments as p')
            ->join('registrations as r', 'r.id', '=', 'p.registration_id')
            ->leftJoin('platform_fee_snapshots as f', 'f.payment_id', '=', 'p.id')
            ->where('r.event_id', $event->id)->whereNull('p.deleted_at')
            ->whereIn('p.status', ['paid', 'pending', 'failed'])
            ->orderByDesc('p.created_at')->limit(500)
            ->get(['p.id', 'p.status', 'p.amount', 'p.paid_at', 'p.failure_reason', 'p.created_at', 'r.purchaser_name', 'r.purchaser_email', 'r.status as registration_status',
                'f.computed_amount as fee',
                DB::raw('(select coalesce(sum(pr.amount), 0) from payment_refunds pr where pr.payment_id = p.id) as refunded'),
                DB::raw('(select coalesce(sum(pr.fee_portion_refunded), 0) from payment_refunds pr where pr.payment_id = p.id) as fee_returned'),
                DB::raw("(select count(*) from tickets t where t.registration_id = r.id and t.status in ('valid', 'used')) as tickets")]);

        $paid = $rows->where('status', 'paid');

        return ApiResponse::success([
            'payments' => $rows->map(fn ($r) => [
                'id' => $r->id, 'status' => $r->status, 'amount' => $r->amount, 'fee' => $r->fee, 'refunded' => $r->refunded,
                'fee_returned' => $r->fee_returned,
                'net' => $r->status === 'paid' ? number_format((float) $r->amount - (float) $r->fee + (float) $r->fee_returned - (float) $r->refunded, 2, '.', '') : null,
                'buyer' => $r->purchaser_name, 'email' => $r->purchaser_email, 'tickets' => (int) $r->tickets,
                'paid_at' => $r->paid_at, 'created_at' => $r->created_at, 'failure_reason' => $r->failure_reason,
            ])->values(),
            'totals' => [
                'gross' => number_format((float) $paid->sum('amount'), 2, '.', ''),
                // Fees NaSeRy keeps: charged minus what valid-reason refunds handed back.
                'fees' => number_format((float) $paid->sum('fee') - (float) $paid->sum('fee_returned'), 2, '.', ''),
                'fee_returned' => number_format((float) $paid->sum('fee_returned'), 2, '.', ''),
                'refunds' => number_format((float) $paid->sum('refunded'), 2, '.', ''),
                'net' => number_format((float) $paid->sum('amount') - (float) $paid->sum('fee') + (float) $paid->sum('fee_returned') - (float) $paid->sum('refunded'), 2, '.', ''),
            ],
        ]);
    }

    public function refund(Request $request, Organization $organization, Event $event, string $payment): JsonResponse
    {
        $this->check($request, $organization, $event, write: true);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'decimal:0,2'],
            'category' => ['required', Rule::enum(RefundReason::class)],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'cancel_tickets' => ['sometimes', 'boolean'],
        ]);

        $model = Payment::query()->whereKey($payment)
            ->whereIn('registration_id', DB::table('registrations')->where('event_id', $event->id)->select('id'))->first()
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);

        $this->payments->refund($model, (float) $data['amount'], RefundReason::from($data['category']), $data['reason'], $request->user(), $request->boolean('cancel_tickets', true));

        return ApiResponse::success(null, 'Refund recorded.');
    }

    private function check(Request $request, Organization $organization, Event $event, bool $write = false): void
    {
        if ($event->organization_id !== $organization->id || ! $this->access->canSeeEvent($request->user(), $event)) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        if (! $this->access->can($request->user(), $organization, 'finance.view')) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to view payments.', 403);
        }

        if ($write && ! $organization->isOperational()) {
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to refund.', 403);
        }
    }
}
