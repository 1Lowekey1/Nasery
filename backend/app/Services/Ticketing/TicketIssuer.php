<?php

namespace App\Services\Ticketing;

use App\Enums\AdmissionType;
use App\Enums\TicketStatus;
use App\Models\Attendee;
use App\Models\QrTicket;
use App\Models\Registration;
use App\Models\Ticket;
use App\Models\TicketType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues valid tickets with their QR credentials for a registration. Runs
 * inside the caller's system-context transaction after authorization and
 * CapacityGuard::reserve() (attendees have no RLS write path, 0025).
 */
class TicketIssuer
{
    public function __construct(private readonly QrTokens $qr) {}

    /**
     * @param  list<array{full_name: string, email?: ?string, contact_number?: ?string, seat_label?: ?string}>  $people
     * @return Collection<int, Ticket>
     */
    public function issue(Registration $registration, TicketType $tier, array $people, AdmissionType $admission): Collection
    {
        $now = now();
        $price = $admission === AdmissionType::Paid ? $tier->price : 0;

        $tickets = collect($people)->map(function (array $person) use ($registration, $tier, $admission, $price, $now) {
            $attendee = Attendee::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'full_name' => trim($person['full_name']),
                'email' => isset($person['email']) && $person['email'] !== '' ? Str::lower(trim($person['email'])) : null,
                'contact_number' => $person['contact_number'] ?? null,
            ]);

            $ticket = Ticket::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'registration_id' => $registration->id,
                'ticket_type_id' => $tier->id,
                'attendee_id' => $attendee->id,
                'event_id' => $registration->event_id,
                'admission_type' => $admission,
                'purchased_price' => $price,
                'status' => TicketStatus::Valid,
                'issued_at' => $now,
                'seat_label' => $person['seat_label'] ?? null, // 0066: a table, when the type has them
            ]);

            $this->issueQr($ticket);

            return $ticket->setRelation('attendee', $attendee);
        });

        $this->refreshSoldCount($tier->id);

        return $tickets;
    }

    /**
     * Paid checkout (B10): record who is going as PENDING tickets. They don't
     * consume capacity themselves (the registration's hold does, 0011) and
     * get no QR until payment settles (activate()). expire_stale_holds()
     * cancels them if the hold runs out.
     *
     * @param  list<array{full_name: string, email?: ?string, contact_number?: ?string, seat_label?: ?string}>  $people
     */
    public function issuePending(Registration $registration, TicketType $tier, array $people): void
    {
        foreach ($people as $person) {
            $attendee = Attendee::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'full_name' => trim($person['full_name']),
                'email' => isset($person['email']) && $person['email'] !== '' ? Str::lower(trim($person['email'])) : null,
                'contact_number' => $person['contact_number'] ?? null,
            ]);

            Ticket::query()->forceCreate([
                'id' => (string) Str::uuid7(),
                'registration_id' => $registration->id,
                'ticket_type_id' => $tier->id,
                'attendee_id' => $attendee->id,
                'event_id' => $registration->event_id,
                'admission_type' => AdmissionType::Paid,
                'purchased_price' => $tier->price,
                'status' => TicketStatus::Pending,
                'seat_label' => $person['seat_label'] ?? null, // 0066: held with the payment hold
            ]);
        }
    }

    /**
     * Payment settled: pending tickets become valid and get their QR codes.
     *
     * @param  array<string, string>  $feeByTicket  platform fee share per ticket id
     * @return Collection<int, Ticket>
     */
    public function activate(Registration $registration, array $feeByTicket): Collection
    {
        $tickets = Ticket::query()->where('registration_id', $registration->id)->where('status', TicketStatus::Pending->value)->lockForUpdate()->get();

        foreach ($tickets as $ticket) {
            $ticket->forceFill(['status' => TicketStatus::Valid, 'issued_at' => now(), 'platform_fee_amount' => $feeByTicket[$ticket->id] ?? 0])->save();
            $this->issueQr($ticket);
        }

        $tickets->pluck('ticket_type_id')->unique()->each(fn ($tierId) => $this->refreshSoldCount($tierId));

        return $tickets;
    }

    /** New (or replacement) QR credential; the previous token stops working. */
    public function issueQr(Ticket $ticket): QrTicket
    {
        $identity = $this->qr->freshIdentity();
        DB::table('qr_tickets')->where('ticket_id', $ticket->id)->delete();

        $qr = new QrTicket;
        $qr->forceFill([
            ...$identity,
            'ticket_id' => $ticket->id,
            'status' => 'active',
            'issued_at' => $identity['issued_at'],
            'token_hash' => 'pending',
        ]);
        $qr->forceFill(['token_hash' => QrTokens::hash($this->qr->token($qr))])->save();

        return $qr;
    }

    /** cached_sold_count is a display cache only (0011); enforcement recomputes. */
    public function refreshSoldCount(string $tierId): void
    {
        $sold = DB::table('tickets')->where('ticket_type_id', $tierId)->whereIn('status', [TicketStatus::Valid->value, TicketStatus::Used->value])->count();
        DB::table('ticket_types')->where('id', $tierId)->update(['cached_sold_count' => $sold]);
    }
}
