<?php

namespace App\Services\Ticketing;

use App\Exceptions\ApiException;
use App\Models\TicketType;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * 0066/0067 (pre-defense, Nov 2026): buyers choose where they sit, per ticket type.
 *
 *   tables: tables or spaces ("VIP Table" x 10 of 8, "Booth" x 30 of 1). A ticket
 *           gets "VIP Table 3": the one chosen, or the next with room for the group.
 *   rows:   rows of numbered seats. Rows may differ in length (0067 seat_rows, from
 *           the stage back) and be named A, B, C or 1, 2, 3 (0067 row_names). A ticket
 *           gets "Row C, Seat 7": exact seats the buyer tapped, a row they chose, or
 *           (none) front rows first; a group gets seats next to each other.
 *
 * A ticket type is one section (Center, Left side...): its name is on the ticket next
 * to the seat. Runs inside the checkout's system-context transaction after
 * CapacityGuard::reserve(), which locks the ticket type row, so two buyers can't take
 * the same place. A place is taken by a valid or used ticket, or a pending one whose
 * payment hold is still running; expired or cancelled ones free it.
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

    /** A row's name as this ticket type writes it: "C" or "3". */
    private static function rowLabel(object $tier, int $row): string
    {
        return ($tier->row_names ?? null) === 'numbers' ? (string) $row : self::rowName($row);
    }

    /**
     * Seats in each row (1-based), from the stage back.
     *
     * @return array<int, int>
     */
    public static function rowLengths(object $tier): array
    {
        $list = $tier->seat_rows ?? null;
        if (is_string($list)) {
            $list = json_decode($list, true);
        }
        if (is_array($list) && $list !== []) {
            $list = array_values(array_map(fn ($n) => max(1, (int) $n), $list));

            return array_combine(range(1, count($list)), $list);
        }

        return array_fill(1, (int) $tier->table_count, (int) $tier->table_size);
    }

    /** A table (tables) or a row (rows), by number. */
    public static function groupName(object $tier, int $number): string
    {
        return self::rows($tier) ? 'Row '.self::rowLabel($tier, $number) : trim((string) $tier->table_label).' '.$number;
    }

    private static function seatLabel(object $tier, int $number, ?int $seat): string
    {
        return self::rows($tier) ? self::groupName($tier, $number).', Seat '.$seat : self::groupName($tier, $number);
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

        $tiers = DB::table('ticket_types')->whereIn('id', $tierIds)->get(['id', 'seating_mode', 'table_label', 'table_count', 'table_size', 'seat_rows', 'row_names'])
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
            $lengths = self::rowLengths($tier);
            $out[$tier->id] = self::rows($tier) ? array_fill(1, count($lengths), []) : array_fill(1, (int) $tier->table_count, 0);
            $rowNumbers = [];
            foreach (self::rows($tier) ? array_keys($lengths) : [] as $r) {
                $rowNumbers[self::rowLabel($tier, $r)] = $r; // "C" => 3, or "3" => 3
            }
            $prefix = trim($tier->table_label).' ';

            foreach ($rows->where('ticket_type_id', $tier->id) as $r) {
                // Only labels this layout gives count; a place typed by hand ("Stage left") doesn't.
                if (self::rows($tier)) {
                    if (preg_match('/^Row ([A-Z]+|\d+), Seat (\d+)$/', $r->seat_label, $m) && isset($rowNumbers[$m[1]])) {
                        $out[$tier->id][$rowNumbers[$m[1]]][(int) $m[2]] = true;
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
     * @param  list<array{0: int, 1: int}>  $picks  rows: exact seats the buyer tapped, [row, seat]
     * @return list<string|null>
     */
    public function assign(TicketType $tier, int $quantity, ?int $choice, array $picks = []): array
    {
        if (! self::enabled($tier)) {
            return array_fill(0, $quantity, null);
        }

        $taken = $this->taken([$tier->id])[$tier->id];
        $lengths = self::rowLengths($tier);
        $size = (int) $tier->table_size;
        $rows = self::rows($tier);

        // Exact seats first (rows only); whatever wasn't picked is filled next to them.
        $seats = [];
        if ($rows && $picks !== []) {
            if (count($picks) > $quantity) {
                throw $this->invalid('Pick at most one seat per ticket.');
            }
            foreach ($picks as [$row, $seat]) {
                $row = (int) $row;
                $seat = (int) $seat;
                if (! isset($lengths[$row]) || $seat < 1 || $seat > $lengths[$row]) {
                    throw $this->invalid('Choose seats from the plan.');
                }
                $label = self::seatLabel($tier, $row, $seat);
                if (isset($taken[$row][$seat]) || in_array($label, $seats, true)) {
                    throw new ApiException(ErrorCode::CapacityExceeded, "{$label} was just taken. Please pick another seat.", 409,
                        ['scope' => 'seat', 'seat' => $label]);
                }
                $taken[$row][$seat] = true;
                $seats[] = $label;
            }
            $quantity -= count($seats);
            if ($quantity === 0) {
                return $seats;
            }
            $choice ??= (int) $picks[0][0];
        }

        $free = $rows
            ? array_map(fn (int $row) => $lengths[$row] - count(array_filter(array_keys($taken[$row]), fn ($s) => $s <= $lengths[$row])), array_combine(array_keys($lengths), array_keys($lengths)))
            : array_map(fn (int $n) => max(0, $size - $n), $taken);

        if ($choice !== null) {
            if (! isset($free[$choice])) {
                throw $this->invalid('Choose one of the '.($rows ? 'rows' : 'places').' listed.');
            }
            if ($free[$choice] >= $quantity) {
                return [...$seats, ...$this->placesIn($tier, $choice, $quantity, $taken[$choice], $lengths)];
            }
            // A row chosen for the rest of a picked group: fall through to any row.
            if ($seats === []) {
                $name = self::groupName($tier, $choice);
                throw new ApiException(ErrorCode::CapacityExceeded, $free[$choice] > 0
                    ? "{$name} just filled up: only {$free[$choice]} ".($free[$choice] === 1 ? 'place' : 'places').' left. Please choose another.'
                    : "{$name} just filled up. Please choose another.", 409, ['scope' => 'table', 'available' => $free[$choice]]);
            }
        }

        // Any: the first table/row (from the front) that fits the whole group...
        foreach ($free as $number => $left) {
            if ($left >= $quantity) {
                return [...$seats, ...$this->placesIn($tier, $number, $quantity, $taken[$number], $lengths)];
            }
        }

        // ...else fill in order. Places moved by hand can leave fewer than tickets: the rest get none.
        foreach ($free as $number => $left) {
            if ($quantity <= 0) {
                break;
            }
            if ($left > 0) {
                $more = $this->placesIn($tier, $number, min($left, $quantity), $taken[$number], $lengths);
                $quantity -= count($more);
                $seats = [...$seats, ...$more];
            }
        }

        return array_pad($seats, count($seats) + max(0, $quantity), null);
    }

    private function invalid(string $message): ApiException
    {
        return new ApiException(ErrorCode::ValidationError, $message, 422, ['fields' => ['table' => [$message]]]);
    }

    /**
     * $quantity places at one table, or in one row: side by side when the row has a long
     * enough gap (the one nearest the middle), else the free seats nearest the middle.
     *
     * @param  int|array<int, true>  $taken
     * @param  array<int, int>  $lengths
     * @return list<string>
     */
    private function placesIn(object $tier, int $number, int $quantity, int|array $taken, array $lengths): array
    {
        if (! self::rows($tier)) {
            return array_fill(0, $quantity, self::seatLabel($tier, $number, null));
        }

        $size = $lengths[$number];
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
        $lengths = self::rowLengths($tier);
        $groups = [];
        $count = $rows ? count($lengths) : (int) $tier->table_count;
        for ($n = 1; $n <= $count; $n++) {
            $length = $rows ? $lengths[$n] : $size;
            $seatsTaken = $rows ? array_values(array_filter(array_map('intval', array_keys($taken[$n] ?? [])), fn ($s) => $s <= $length)) : null;
            $groups[] = [
                'number' => $n,
                'name' => self::groupName($tier, $n),
                'left' => $rows ? $length - count($seatsTaken) : max(0, $size - (int) ($taken[$n] ?? 0)),
                ...($rows ? ['taken' => $seatsTaken, 'seats' => $length] : []),
            ];
        }

        return ['mode' => $rows ? self::ROWS : self::TABLES, 'label' => trim($tier->table_label), 'size' => $size, 'tables' => $groups];
    }
}
