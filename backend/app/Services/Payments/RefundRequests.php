<?php

namespace App\Services\Payments;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Services\Notifications\AdminAlerts;
use App\Services\Notifications\Notifier;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Buyers asking for their money back (0061, mock defense 2026-11). The buyer
 * asks from My Tickets; the organizer refunds it through the normal payment
 * refund (Payments::refund marks the request refunded) or declines it with a
 * note. Only purchases paid online can be refunded here.
 */
class RefundRequests
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly AdminAlerts $adminAlerts,
    ) {}

    /** The buyer's view of their purchase: can they ask, and what happened to the last request. */
    public function stateFor(string $registrationId): array
    {
        return $this->db->system('refund.state', function () use ($registrationId) {
            $payment = $this->paidPayment($registrationId);
            $last = DB::table('refund_requests')->where('registration_id', $registrationId)->orderByDesc('created_at')->orderByDesc('id')
                ->first(['status', 'response_note']);
            $remaining = $payment ? $this->remainingCents($payment) : 0;
            $returned = $payment ? DB::table('payment_refunds')->where('payment_id', $payment->id)
                ->selectRaw('coalesce(sum(amount), 0) as amount, max(refunded_at) as at')->first() : null;

            return [
                'paid' => $payment !== null,
                'can_request' => $payment !== null && $remaining > 0 && $last?->status !== 'pending',
                'request_status' => $last?->status,
                'response_note' => $last?->response_note,
                // Money already sent back to the buyer (any refund, asked for or not).
                'paid_amount' => $payment ? number_format((float) $payment->amount, 2, '.', '') : null,
                'refunded_amount' => $returned && (float) $returned->amount > 0 ? number_format((float) $returned->amount, 2, '.', '') : null,
                'refunded_at' => $returned?->at ? \Carbon\CarbonImmutable::parse($returned->at)->toIso8601String() : null,
            ];
        });
    }

    public function request(User $user, string $ticketId, string $reason): void
    {
        $reason = trim($reason);
        $row = $this->db->system('refund.request', function () use ($user, $ticketId, $reason) {
            $ticket = DB::table('tickets as t')->join('registrations as r', 'r.id', '=', 't.registration_id')->join('events as e', 'e.id', '=', 'r.event_id')
                ->where('t.id', $ticketId)->whereNull('t.deleted_at')
                ->first(['r.id as registration_id', 'r.purchaser_user_id', 'e.id as event_id', 'e.organization_id', 'e.name as event']);
            if ($ticket === null || $ticket->purchaser_user_id !== $user->id) {
                throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            }

            DB::table('registrations')->where('id', $ticket->registration_id)->lockForUpdate()->value('id');
            $payment = $this->paidPayment($ticket->registration_id);
            if ($payment === null || $this->remainingCents($payment) <= 0) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'There is nothing left to refund on this purchase.', 409);
            }
            if (DB::table('refund_requests')->where('payment_id', $payment->id)->where('status', 'pending')->exists()) {
                throw new ApiException(ErrorCode::Conflict, 'You already asked for a refund. The organizer will answer soon.', 409);
            }

            $ticket->request_id = (string) Str::uuid7();
            DB::table('refund_requests')->insert([
                'id' => $ticket->request_id, 'payment_id' => $payment->id, 'registration_id' => $ticket->registration_id,
                'event_id' => $ticket->event_id, 'organization_id' => $ticket->organization_id, 'requested_by' => $user->id, 'reason' => $reason,
            ]);

            return $ticket;
        });

        $this->audit->record('refund.requested', ['refund_requests', $row->request_id], new: ['registration_id' => $row->registration_id, 'reason' => $reason],
            organizationId: $row->organization_id, actorUserId: $user->id);
        $this->notifier->send($this->adminAlerts->owners($row->organization_id), 'refund.requested', "A buyer asked for a refund for {$row->event}",
            "{$user->first_name} {$user->last_name}: {$reason}", ['event_id' => $row->event_id],
            "/organizer/ticketattendance/payments?event={$row->event_id}");
    }

    public function decline(string $eventId, string $requestId, User $by, ?string $note): void
    {
        $note = filled($note) ? trim((string) $note) : null;
        $row = $this->db->system('refund.decline', function () use ($eventId, $requestId, $by, $note) {
            $row = DB::table('refund_requests as q')->join('events as e', 'e.id', '=', 'q.event_id')
                ->where('q.id', $requestId)->where('q.event_id', $eventId)->lockForUpdate()
                ->first(['q.id', 'q.status', 'q.requested_by', 'q.organization_id', 'e.name as event']);
            if ($row === null) {
                throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
            }
            if ($row->status !== 'pending') {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'This request was already answered.', 409);
            }
            DB::table('refund_requests')->where('id', $row->id)->update([
                'status' => 'declined', 'response_note' => $note, 'decided_by' => $by->id, 'decided_at' => now(),
            ]);

            return $row;
        });

        $this->audit->record('refund.request_declined', ['refund_requests', $row->id], new: ['request_id' => $row->id, 'note' => $note], organizationId: $row->organization_id, actorUserId: $by->id);
        $this->notifier->send($row->requested_by, 'refund.answered', "Your refund request for {$row->event} was declined",
            $note ? "The organizer's note: {$note}" : 'Contact the organizer if you have questions.', [], '/my-tickets');
    }

    /** Called by Payments::refund inside its transaction: a refund answers the open request. */
    public function markRefunded(string $paymentId, string $byUserId): void
    {
        DB::table('refund_requests')->where('payment_id', $paymentId)->where('status', 'pending')
            ->update(['status' => 'refunded', 'decided_by' => $byUserId, 'decided_at' => now()]);
    }

    private function paidPayment(string $registrationId): ?object
    {
        return DB::table('payments')->where('registration_id', $registrationId)->where('status', 'paid')->whereNull('deleted_at')
            ->orderByDesc('created_at')->first(['id', 'amount']);
    }

    private function remainingCents(object $payment): int
    {
        $refunded = (float) DB::table('payment_refunds')->where('payment_id', $payment->id)->sum('amount');

        return (int) round(((float) $payment->amount - $refunded) * 100);
    }
}
