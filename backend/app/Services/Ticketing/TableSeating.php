<?php

namespace App\Services\Ticketing;

use App\Exceptions\ApiException;
use App\Models\TicketType;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * 0066 (pre-defense, Nov 2026): buyers choose where they sit, per ticket type.
 *
 *   tables: tables or spaces ("VIP Table" x 10 of 8, "Booth" x 30 of 1). A ticket
 *           gets "VIP Table 3": the one chosen, or the next with room for the group.
 *   rows:   rows of numbered seats (10 rows x 20). A ticket gets "Row C, Seat 7": the
 *           buyer picks a row (or none: front rows first) and the group gets seats
 *           next to each other when the row has them.
 *
 * Runs inside the checkout's system-context transaction after CapacityGuard::reserve(),
 * which locks the ticket type row, so two buyers can't take the same last place. A
 * place is taken by a valid or used ticket, or a pending one whose payment hold is
 * still running; expired or cancelled ones free it.
 */
class TableSeating
{
    public const TABLES = 'tables';

    public const ROWS = 'rows';

    public static function enabled(object $tier): bool
    {
        return in_array($tier->seating_mode ?? null, [self::TABLES, self::ROWS], true)
            && filled($tier->table_label ?? null) && (int) ($tier->table_count ?? 0) > 0 && (int) ($tier->table_size ?? 0) > 0;
    }

    private static function rows(object $tier): bool
    {
        return ($tier->seating_mode ?? null) === self::ROWS;
    }

    /** "A".."Z", then "AA", "AB"... */
    public static function rowName(int $row): string
    {
        $name = '';
        for ($n = $row; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** A table (tables) or a row (rows), by number. */
    public static function groupName(object $tier, int $number): string
    {
        return self::rows($tier) ? 'Row '.self::rowName($number) : trim((string) $tier->table_label).' '.$number;
    }

    private static function seatLabel(object $tier, int $number, ?int $seat): string
    {
        return self::rows($tier) ? 'Row '.self::rowName($number).', Seat '.$seat : self::groupName($tier, $number);
    }

    /**
     * Places taken, per ticket type: tables => [table => count]; rows => [row => [seat => true]].
     *
     * @param  list<string>  $tierIds
     * @return array<string, array<int, int|array<int, true>>>
     */
    public function taken(array $tierIds): array
    {
        if ($tierIds === []) {
            return [];
        }

        $tiers = DB::table('ticket_types')->whereIn('id', $tierIds)->get(['id', 'seating_mode', 'table_label', 'table_count', 'table_size'])
            ->filter(fn ($t) => self::enabled($t));
        if ($tiers->isEmpty()) {
            return [];
        }

        $rows = DB::table('tickets as t')->whereIn('t.ticket_type_id', $tiers->pluck('id'))->whereNull('t.deleted_at')->whereNotNull('t.seat_label')
            ->where(fn ($q) => $q->whereIn('t.status', ['valid', 'used'])
                ->orWhere(fn ($p) => $p->where('t.status', 'pending')->whereExists(fn ($h) => $h->from('ticket_holds as h')
                    ->whereColumn('h.registration_id', 't.registration_id')->where('h.status', 'active')->where('h.expires_at', '>', now()))))
            ->groupBy('t.ticket_type_id', 't.seat_label')
            ->get(['t.ticket_type_id', 't.seat_label', DB::raw('count(*) as n')]);

        $out = [];
        foreach ($tiers as $tier) {
            $count = (int) $tier->table_count;
            $out[$tier->id] = self::rows($tier) ? array_fill(1, $count, []) : array_fill(1, $count, 0);
            $rowNumbers = self::rows($tier) ? array_flip(array_map(fn ($r) => self::rowName($r), range(1, $count))) : [];
            $prefix = trim($tier->table_label).' ';

            foreach ($rows->where('ticket_type_id', $tier->id) as $r) {
                // Only labels this layout gives count; a place typed by hand ("Stage left") doesn't.
                if (self::rows($tier)) {
                    if (preg_match('/^Row ([A-Z]+), Seat (\d+)$/', $r->seat_label, $m) && isset($rowNumbers[$m[1]])) {
                        $out[$tier->id][$rowNumbers[$m[1]] + 1][(int) $m[2]] = true;
                    }
                } elseif (str_starts_with($r->seat_label, $prefix) && ctype_digit($n = substr($r->seat_label, strlen($prefix))) && isset($out[$tier->id][(int) $n])) {
                    $out[$tier->id][(int) $n] += (int) $r->n;
                }
            }
        }

        return $out;
    }

    /**
     * Places for $quantity new tickets: one label each (null when the type has no layout).
     *
     * @return list<string|null>
     */
    public function assign(TicketType $tier, int $quantity, ?int $choice): array
    {
        if (! self::enabled($tier)) {
            return array_fill(0, $quantity, null);
        }

        $taken = $this->taken([$tier->id])[$tier->id];
        $size = (int) $tier->table_size;
        $free = self::rows($tier)
            ? array_map(fn (array $seats) => $size - count(array_filter(array_keys($seats), fn ($s) => $s <= $size)), $taken)
            : array_map(fn (int $n) => max(0, $size - $n), $taken);

        if ($choice !== null) {
            if (! isset($free[$choice])) {
                $what = self::rows($tier) ? 'rows' : 'places';
                throw new ApiException(ErrorCode::ValidationError, "Choose one of the {$what} listed.", 422, ['fields' => ['table' => ["Choose one of the {$what} listed."]]]);
            }
            if ($free[$choice] < $quantity) {
                $name = self::groupName($tier, $choice);
                throw new ApiException(ErrorCode::CapacityExceeded, $free[$choice] > 0
                    ? "{$name} just filled up: only {$free[$choice]} ".($free[$choice] === 1 ? 'place' : 'places').' left. Please choose another.'
                    : "{$name} just filled up. Please choose another.", 409, ['scope' => 'table', 'available' => $free[$choice]]);
            }

            return $this->placesIn($tier, $choice, $quantity, $taken[$choice]);
        }

        // Any: the first table/row (from the front) that fits the whole group...
        foreach ($free as $number => $left) {
            if ($left >= $quantity) {
                return $this->placesIn($tier, $number, $quantity, $taken[$number]);
            }
        }

        // ...else fill in order. Places moved by hand can leave fewer than tickets: the rest get none.
        $seats = [];
        foreach ($free as $number => $left) {
            if ($left > 0 && count($seats) < $quantity) {
                $seats = [...$seats, ...$this->placesIn($tier, $number, min($left, $quantity - count($seats)), $taken[$number])];
            }
        }

        return array_pad($seats, $quantity, null);
    }

    /**
     * $quantity places at one table, or in one row: side by side when the row has a long
     * enough gap (the one nearest the middle), else the free seats nearest the middle.
     *
     * @param  int|array<int, true>  $taken
     * @return list<string>
     */
    private function placesIn(object $tier, int $number, int $quantity, int|array $taken): array
    {
        if (! self::rows($tier)) {
            return array_fill(0, $quantity, self::seatLabel($tier, $number, null));
        }

        $size = (int) $tier->table_size;
        $open = array_values(array_filter(range(1, $size), fn ($s) => ! isset($taken[$s])));
        $middle = ($size + 1) / 2;

        $best = null;
        for ($i = 0; $i + $quantity <= count($open); $i++) {
            $block = array_slice($open, $i, $quantity);
            if (end($block) - $block[0] === $quantity - 1) {
                $distance = abs(($block[0] + end($block)) / 2 - $middle);
                if ($best === null || $distance < $best[0]) {
                    $best = [$distance, $block];
                }
            }
        }

        if ($best === null) {
            usort($open, fn ($a, $b) => abs($a - $middle) <=> abs($b - $middle) ?: $a <=> $b);
            $block = array_slice($open, 0, $quantity);
            sort($block);
        } else {
            $block = $best[1];
        }

        return array_map(fn ($seat) => self::seatLabel($tier, $number, $seat), $block);
    }

    /**
     * For the public event page.
     *
     * @return array<string, mixed>|null
     */
    public function forBuyers(object $tier, array $taken): ?array
    {
        if (! self::enabled($tier)) {
            return null;
        }

        $size = (int) $tier->table_size;
        $rows = self::rows($tier);
        $groups = [];
        for ($n = 1; $n <= (int) $tier->table_count; $n++) {
            $seatsTaken = $rows ? array_values(array_filter(array_map('intval', array_keys($taken[$n] ?? [])), fn ($s) => $s <= $size)) : null;
            $groups[] = [
                'number' => $n,
                'name' => self::groupName($tier, $n),
                'left' => $rows ? $size - count($seatsTaken) : max(0, $size - (int) ($taken[$n] ?? 0)),
                ...($rows ? ['taken' => $seatsTaken] : []),
            ];
        }

        return ['mode' => $rows ? self::ROWS : self::TABLES, 'label' => trim($tier->table_label), 'size' => $size, 'tables' => $groups];
    }
}
