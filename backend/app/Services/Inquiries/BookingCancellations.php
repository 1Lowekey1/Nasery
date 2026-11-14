<?php

namespace App\Services\Inquiries;

use App\Enums\EventStatus;
use App\Enums\InquiryStatus;
use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\Inquiry;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Events\EventManager;
use App\Services\Notifications\Notifier;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Backing out after an offer is accepted (0060). Either side asks with a
 * reason; the other agrees or keeps the booking. Silence until respond_by
 * counts as agreeing. Only bookings whose event hasn't started can be
 * cancelled. An agreed cancellation goes through the normal event cancel, so
 * tickets are voided and holders are told the usual way.
 */
class BookingCancellations
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly Notifier $notifier,
        private readonly PlatformSettings $settings,
        private readonly EventManager $events,
    ) {}

    /** @return array<string, mixed> what this side sees and may do */
    public function state(Inquiry $inquiry, string $side): array
    {
        return $this->db->system('booking.cancel_state', function () use ($inquiry, $side) {
            $row = DB::table('booking_cancellations')->where('inquiry_id', $inquiry->id)->orderByDesc('created_at')->orderByDesc('id')->first();
            $pending = $row?->status === 'pending';

            return [
                'cancellation' => $row ? [
                    'id' => $row->id,
                    'status' => $row->status,
                    'requested_by_side' => $row->requested_by_side,
                    'reason' => $row->reason,
                    'respond_by' => CarbonImmutable::parse($row->respond_by)->toIso8601String(),
                    'response_note' => $row->response_note,
                    'decided_at' => $row->decided_at ? CarbonImmutable::parse($row->decided_at)->toIso8601String() : null,
                    'can_respond' => $pending && $row->requested_by_side !== $side,
                    'can_withdraw' => $pending && $row->requested_by_side === $side,
                ] : null,
                'can_request' => ! $pending && $this->cancellable($inquiry),
                'grace_hours' => (int) $this->settings->get('cancellation_grace_hours'),
            ];
        });
    }

    public function request(Inquiry $inquiry, User $user, string $side, string $reason): void
    {
        $organizationId = $this->bookedOrganization($inquiry);
        $reason = trim($reason);

        $this->db->system('booking.cancel_request', function () use ($inquiry, $user, $side, $reason, $organizationId) {
            DB::table('inquiries')->where('id', $inquiry->id)->lockForUpdate()->value('id');

            if (! $this->cancellable($inquiry)) {
                throw new ApiException(ErrorCode::InvalidStateTransition, 'This booking can no longer be cancelled here.', 409);
            }
            if (DB::table('booking_cancellations')->where('inquiry_id', $inquiry->id)->where('status', 'pending')->exists()) {
                throw new ApiException(ErrorCode::Conflict, 'A cancellation request is already waiting for an answer.', 409);
            }

            DB::table('booking_cancellations')->insert([
                'id' => (string) Str::uuid7(),
                'inquiry_id' => $inquiry->id,
                'organization_id' => $organizationId,
                'requested_by' => $user->id,
                'requested_by_side' => $side,
                'reason' => $reason,
                'respond_by' => now()->addHours((int) $this->settings->get('cancellation_grace_hours')),
            ]);
        });

        $this->audit->record('booking.cancellation_requested', $inquiry, new: ['side' => $side, 'reason' => $reason], organizationId: $organizationId, actorUserId: $user->id);
        $hours = (int) $this->settings->get('cancellation_grace_hours');
        $this->tell($inquiry, $organizationId, $side === 'client' ? 'organizer' : 'client', 'A request to cancel your booking',
            "Reason: {$reason}. Agree or keep the booking within {$hours} hours, or it will be cancelled.");
    }

    public function respond(Inquiry $inquiry, User $user, string $side, bool $agree, ?string $note): void
    {
        $row = $this->pending($inquiry);
        if ($row->requested_by_side === $side) {
            throw new ApiException(ErrorCode::Forbidden, 'The other side answers this request.', 403);
        }

        $note = filled($note) ? trim((string) $note) : null;
        $this->close($row, $agree ? 'agreed' : 'declined', $user->id, $note);

        if ($agree) {
            $this->cancelBooking($inquiry, $row);
        }

        $this->audit->record($agree ? 'booking.cancellation_agreed' : 'booking.cancellation_declined', $inquiry,
            new: ['note' => $note], organizationId: $row->organization_id, actorUserId: $user->id);
        $this->tell($inquiry, $row->organization_id, $row->requested_by_side,
            $agree ? 'Your booking was cancelled' : 'Your cancellation request was declined',
            $agree ? 'The other side agreed to cancel.' : 'The other side wants to keep the booking.'.($note ? " Note: {$note}" : ''));
    }

    public function withdraw(Inquiry $inquiry, User $user, string $side): void
    {
        $row = $this->pending($inquiry);
        if ($row->requested_by_side !== $side) {
            throw new ApiException(ErrorCode::Forbidden, 'Only the side that asked can withdraw this request.', 403);
        }

        $this->close($row, 'withdrawn', $user->id, null);
        $this->audit->record('booking.cancellation_withdrawn', $inquiry, organizationId: $row->organization_id, actorUserId: $user->id);
        $this->tell($inquiry, $row->organization_id, $side === 'client' ? 'organizer' : 'client', 'A cancellation request was withdrawn', 'The booking stays as it is.');
    }

    /** Requests nobody answered in time count as agreed. Returns how many. */
    public function expire(): int
    {
        $due = $this->db->system('booking.cancel_due', fn () => DB::table('booking_cancellations')
            ->where('status', 'pending')->where('respond_by', '<=', now())->get());

        foreach ($due as $row) {
            $inquiry = $this->db->system('booking.cancel_inquiry', fn () => Inquiry::query()->find($row->inquiry_id));
            $this->close($row, 'auto_agreed', null, null);
            $this->cancelBooking($inquiry, $row);
            $this->audit->record('booking.cancellation_auto_agreed', $inquiry, organizationId: $row->organization_id);

            foreach (['client', 'organizer'] as $side) {
                $this->tell($inquiry, $row->organization_id, $side, 'Your booking was cancelled', 'The time to answer the cancellation request passed, so it was cancelled.');
            }
        }

        return $due->count();
    }

    /** The organization holding the accepted offer, or 404 if this isn't a booking. */
    public function bookedOrganization(Inquiry $inquiry): string
    {
        return $this->db->system('booking.org', fn () => DB::table('quotations')->where('inquiry_id', $inquiry->id)
            ->where('status', 'accepted')->whereNull('deleted_at')->value('organization_id'))
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
    }

    private function cancellable(Inquiry $inquiry): bool
    {
        if (! in_array($inquiry->fresh()->status, [InquiryStatus::Accepted, InquiryStatus::ConvertedToEvent], true)) {
            return false;
        }
        $status = DB::table('events')->where('inquiry_id', $inquiry->id)->whereNull('deleted_at')->value('status');

        return $status === null || in_array($status, [EventStatus::Draft->value, EventStatus::Published->value], true);
    }

    private function pending(Inquiry $inquiry): object
    {
        return $this->db->system('booking.cancel_pending', fn () => DB::table('booking_cancellations')
            ->where('inquiry_id', $inquiry->id)->where('status', 'pending')->first())
            ?? throw new ApiException(ErrorCode::NotFound, 'There is no cancellation request waiting.', 404);
    }

    private function close(object $row, string $status, ?string $userId, ?string $note): void
    {
        $this->db->system('booking.cancel_close', fn () => DB::table('booking_cancellations')->where('id', $row->id)->update([
            'status' => $status, 'responded_by' => $userId, 'response_note' => $note, 'decided_at' => now(),
        ]));
    }

    private function cancelBooking(Inquiry $inquiry, object $row): void
    {
        $this->db->system('booking.cancel_apply', function () use ($inquiry, $row) {
            $event = Event::query()->where('inquiry_id', $inquiry->id)->first();
            if ($event !== null && in_array($event->status, [EventStatus::Draft, EventStatus::Published], true)) {
                $this->events->transition($event, 'cancel', "Booking cancelled: {$row->reason}");
            }

            DB::table('inquiries')->where('id', $inquiry->id)->update(['status' => InquiryStatus::Cancelled->value, 'updated_at' => now()]);
            $quotation = DB::table('quotations')->where('inquiry_id', $inquiry->id)->where('status', 'accepted')->value('id');
            if ($quotation !== null) {
                DB::table('quotations')->where('id', $quotation)->update(['status' => 'cancelled', 'updated_at' => now()]);
                DB::table('quotation_status_history')->insert([
                    'quotation_id' => $quotation, 'from_status' => 'accepted', 'to_status' => 'cancelled', 'reason' => 'Booking cancelled',
                ]);
            }
        });
    }

    private function tell(Inquiry $inquiry, string $organizationId, string $side, string $title, string $body): void
    {
        $user = $side === 'client' ? $inquiry->client_user_id : $this->db->system('booking.owner', fn () => DB::table('organization_members')
            ->where('organization_id', $organizationId)->where('is_primary_owner', true)->value('user_id'));

        $this->notifier->send($user, 'booking.cancellation', $title, $body, ['inquiry_id' => $inquiry->id],
            $side === 'client' ? "/client/inquiries/{$inquiry->id}" : '/organizer/inquiries/offers');
    }
}
