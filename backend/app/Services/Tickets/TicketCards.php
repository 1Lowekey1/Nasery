<?php

namespace App\Services\Tickets;

use App\Enums\TicketStatus;
use App\Models\QrTicket;
use App\Services\Ticketing\QrTokens;
use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything a ticket e-mail or PDF shows, for a set of tickets (B13). The
 * QR token is derived on the fly (only its hash is stored, B9) and only for
 * tickets that can still admit someone.
 */
class TicketCards
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly QrTokens $qr,
    ) {}

    /**
     * @param  list<string>  $ticketIds
     * @return Collection<int, array<string, mixed>>
     */
    public function load(array $ticketIds): Collection
    {
        if ($ticketIds === []) {
            return collect();
        }

        return $this->db->system('tickets.cards', function () use ($ticketIds) {
            $rows = DB::table('tickets as t')
                ->join('attendees as a', 'a.id', '=', 't.attendee_id')
                ->join('registrations as r', 'r.id', '=', 't.registration_id')
                ->join('ticket_types as tt', 'tt.id', '=', 't.ticket_type_id')
                ->join('events as e', 'e.id', '=', 't.event_id')
                ->join('organizations as o', 'o.id', '=', 'e.organization_id')
                ->whereIn('t.id', $ticketIds)->whereNull('t.deleted_at')
                ->orderBy('a.full_name')
                ->get(['t.id', 't.status', 't.event_id', 't.purchased_price', 'a.full_name', 'a.email as attendee_email', 'r.purchaser_email', 'r.purchaser_name',
                    'tt.name as tier', 'tt.zone_name', 't.seat_label', 'tt.benefits', 'e.name as event', 'e.status as event_status', 'e.timezone', 'e.venue_name', 'e.venue_address',
                    'e.organization_id', 'o.display_name as organizer']);

            $qrs = QrTicket::query()->whereIn('ticket_id', $rows->pluck('id'))->where('status', '!=', 'invalidated')->get()->keyBy('ticket_id');
            $sessions = DB::table('event_sessions')->whereIn('event_id', $rows->pluck('event_id')->unique())->whereNull('deleted_at')
                ->where('status', '!=', 'cancelled')->orderBy('starts_at')->get(['event_id', 'name', 'starts_at', 'ends_at'])->groupBy('event_id');

            return $rows->map(function ($r) use ($qrs, $sessions) {
                $tz = $r->timezone ?: 'Asia/Manila';
                $usable = in_array($r->status, [TicketStatus::Valid->value, TicketStatus::Used->value], true)
                    && ! in_array($r->event_status, ['completed', 'cancelled'], true);

                return [
                    'id' => $r->id,
                    'code' => strtoupper(substr(str_replace('-', '', $r->id), -8)),
                    'status' => $r->status,
                    'attendee' => $r->full_name,
                    'recipient' => $r->attendee_email ?: $r->purchaser_email,
                    'recipient_name' => $r->attendee_email ? $r->full_name : ($r->purchaser_name ?: $r->full_name),
                    'tier' => $r->tier,
                    'zone' => $r->zone_name,
                    'seat' => $r->seat_label,
                    'benefits' => json_decode((string) $r->benefits, true) ?? [],
                    'price' => $r->purchased_price,
                    'event_id' => $r->event_id,
                    'organization_id' => $r->organization_id,
                    'event' => $r->event,
                    'organizer' => $r->organizer,
                    'venue' => trim(implode(', ', array_filter([$r->venue_name, $r->venue_address]))) ?: null,
                    'sessions' => collect($sessions[$r->event_id] ?? [])->map(fn ($s) => [
                        'name' => $s->name,
                        'when' => CarbonImmutable::parse($s->starts_at)->setTimezone($tz)->format('D, M j, Y · g:i A')
                            .' – '.CarbonImmutable::parse($s->ends_at)->setTimezone($tz)->format('g:i A'),
                    ])->values()->all(),
                    'token' => $usable && isset($qrs[$r->id]) ? $this->qr->token($qrs[$r->id]) : null,
                ];
            });
        });
    }
}
