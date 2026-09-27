<?php

namespace App\Services\Ticketing;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\TicketType;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Capacity enforcement (pack: a transactional invariant). Locks the tier
 * row and, when the event has an overall capacity, the event row, then
 * recomputes consumption with the 0021 helper functions (valid/used
 * tickets + active unexpired holds (+ unlinked walk-ins for the event)).
 * Must run inside the caller's system-context transaction: the helpers
 * count every ticket, which a self-registering attendee can't see under
 * RLS.
 */
class CapacityGuard
{
    /**
     * @param  bool  $allowEventOverflow  B12: a manager may admit door sales past the
     *                                    venue's overall capacity (never past a tier's)
     * @return int places over the overall capacity after this (0 unless overflow was allowed)
     */
    public function reserve(Event $event, TicketType $tier, int $quantity, bool $allowEventOverflow = false): int
    {
        $tierRow = DB::table('ticket_types')->where('id', $tier->id)->lockForUpdate()->first(['capacity']);
        $tierLeft = (int) $tierRow->capacity - (int) DB::scalar('select ticket_type_consumed_capacity(?)', [$tier->id]);

        if ($quantity > $tierLeft) {
            throw new ApiException(ErrorCode::CapacityExceeded,
                $tierLeft > 0 ? "Only {$tierLeft} left in {$tier->name}." : "{$tier->name} is sold out.", 409, ['available' => max(0, $tierLeft), 'scope' => 'tier']);
        }

        return $this->reserveEventPlaces($event, $quantity, $allowEventOverflow);
    }

    /**
     * Places that count only against the event's overall capacity (B12
     * walk-ins without a ticket). Locks the event row.
     *
     * @return int places over the overall capacity after this (0 unless overflow was allowed)
     */
    public function reserveEventPlaces(Event $event, int $quantity, bool $allowOverflow = false): int
    {
        $eventCapacity = DB::table('events')->where('id', $event->id)->lockForUpdate()->value('overall_capacity');

        if ($eventCapacity === null) {
            return 0;
        }

        $eventLeft = (int) $eventCapacity - (int) DB::scalar('select event_consumed_capacity(?)', [$event->id]);

        if ($quantity <= $eventLeft) {
            return 0;
        }

        if (! $allowOverflow) {
            throw new ApiException(ErrorCode::CapacityExceeded,
                $eventLeft > 0 ? "Only {$eventLeft} places left at this event." : 'This event is full.', 409,
                ['available' => max(0, $eventLeft), 'scope' => 'event']);
        }

        return $quantity - max(0, $eventLeft);
    }

    /**
     * Remaining places per tier (never negative), for listings.
     *
     * @param  list<string>  $tierIds
     * @return array<string, int>
     */
    public function remaining(array $tierIds): array
    {
        if ($tierIds === []) {
            return [];
        }

        return DB::table('ticket_types')->whereIn('id', $tierIds)
            ->selectRaw('id, greatest(0, capacity - ticket_type_consumed_capacity(id)) as remaining')
            ->pluck('remaining', 'id')->map(fn ($n) => (int) $n)->all();
    }
}
