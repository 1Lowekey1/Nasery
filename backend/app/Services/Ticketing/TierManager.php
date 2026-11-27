<?php

namespace App\Services\Ticketing;

use App\Exceptions\ApiException;
use App\Models\Event;
use App\Models\TicketType;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ticket / invitation tiers of an event (paper: pricing, capacity limit,
 * access zone, benefits). Written under RLS as the member (ticket_types_org).
 * Capacity can't drop below what is already sold or held; a tier with
 * tickets can't be deleted (close it instead).
 */
class TierManager
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function list(Event $event): Collection
    {
        $tiers = TicketType::query()->where('event_id', $event->id)->orderBy('sort_order')->orderBy('created_at')->get();

        $counts = $tiers->isEmpty() ? collect() : DB::table('ticket_types as tt')->whereIn('tt.id', $tiers->pluck('id'))
            ->selectRaw("tt.id,
                (select count(*) from tickets t where t.ticket_type_id = tt.id and t.status in ('valid', 'used')) as sold,
                (select count(*) from tickets t where t.ticket_type_id = tt.id and t.status = 'used') as checked_in,
                (select coalesce(sum(h.quantity), 0) from ticket_holds h where h.ticket_type_id = tt.id and h.status = 'active' and h.expires_at > now()) as held")
            ->get()->keyBy('id');

        return $tiers->map(fn (TicketType $t) => self::present($t, $event->timezone, $counts[$t->id] ?? null))->values();
    }

    /** The automatic invitations tier (0065): free, closed (never on sale). */
    public const GUEST_LIST_NOTE = 'Invited guests (never on sale).';

    /**
     * Adviser consultation (Nov 2026): every event has a "Guest list" tier for invited guests,
     * public events too. Returns the existing one, or adds it (capacity: the event's overall
     * capacity, which limits every ticket anyway; 100 when it has none).
     */
    public function ensureGuestList(Event $event): TicketType
    {
        $existing = TicketType::query()->where('event_id', $event->id)->where('price', 0)
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower(TicketType::GUEST_LIST)])->first();

        return $existing ?? $this->create($event, ['name' => TicketType::GUEST_LIST, 'description' => self::GUEST_LIST_NOTE, 'price' => 0,
            'capacity' => max(1, (int) ($event->overall_capacity ?? 100)), 'status' => 'closed', 'sort_order' => 999]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Event $event, array $data): TicketType
    {
        $this->assertOpen($event);
        $data = $this->tables($data, null);

        $tier = new TicketType($this->normalize($data, $event));
        $tier->forceFill(['event_id' => $event->id])->save();
        $this->audit->record('ticket_type.created', $tier, new: [...$data, 'event_id' => $event->id], organizationId: $event->organization_id);

        return $tier->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Event $event, TicketType $tier, array $data): TicketType
    {
        $this->assertOpen($event);
        $locked = DB::table('ticket_types')->where('id', $tier->id)->lockForUpdate()->first();
        $data = $this->tables($data, $locked);

        if (array_key_exists('capacity', $data)) {
            $consumed = (int) DB::scalar('select ticket_type_consumed_capacity(?)', [$tier->id]);

            if ((int) $data['capacity'] < $consumed) {
                throw ValidationException::withMessages(['capacity' => "Capacity can't be lower than the {$consumed} places already taken."]);
            }
        }

        $old = collect((array) $locked)->only(array_keys($data))->all();
        $tier->fill($this->normalize($data, $event))->save();
        $this->audit->record('ticket_type.updated', $tier, old: $old, new: [...$data, 'event_id' => $event->id], organizationId: $event->organization_id);

        return $tier;
    }

    public function delete(Event $event, TicketType $tier): void
    {
        $this->assertOpen($event);

        $used = DB::table('tickets')->where('ticket_type_id', $tier->id)->exists()
            || DB::table('ticket_holds')->where('ticket_type_id', $tier->id)->where('status', 'active')->exists();

        if ($used) {
            throw new ApiException(ErrorCode::Conflict, 'This tier already has tickets. Close it instead of deleting it.', 409);
        }

        $tier->delete();
        $this->audit->record('ticket_type.removed', $tier, new: ['name' => $tier->name, 'event_id' => $event->id], organizationId: $event->organization_id);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(TicketType $t, string $tz, ?object $counts): array
    {
        $sold = (int) ($counts->sold ?? 0);
        $held = (int) ($counts->held ?? 0);

        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'zone_name' => $t->zone_name,
            'benefits' => $t->benefits ?? [],
            'price' => $t->price,
            'currency' => $t->currency,
            'capacity' => $t->capacity,
            'status' => $t->status->value,
            'sort_order' => $t->sort_order,
            'sales_start_at' => $t->sales_start_at?->setTimezone($tz)->toIso8601String(),
            'sales_end_at' => $t->sales_end_at?->setTimezone($tz)->toIso8601String(),
            'sales_start_local' => $t->sales_start_at?->setTimezone($tz)->format('Y-m-d\TH:i'),
            'sales_end_local' => $t->sales_end_at?->setTimezone($tz)->format('Y-m-d\TH:i'),
            'seating_mode' => $t->seating_mode,
            'table_label' => $t->table_label,
            'table_count' => $t->table_count,
            'seat_rows' => $t->seat_rows,
            'row_names' => $t->row_names,
            'table_size' => $t->table_size,
            'sold' => $sold,
            'checked_in' => (int) ($counts->checked_in ?? 0),
            'held' => $held,
            'remaining' => max(0, $t->capacity - $sold - $held),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data, Event $event): array
    {
        foreach (['sales_start_at', 'sales_end_at'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = blank($data[$field]) ? null : CarbonImmutable::parse($data[$field], $event->timezone)->utc();
            }
        }

        if (isset($data['benefits'])) {
            $data['benefits'] = array_values(array_filter(array_map(fn ($b) => trim((string) $b), $data['benefits']), 'strlen'));
        }

        if (isset($data['name'])) {
            $data['name'] = trim($data['name']);
        }

        return $data;
    }

    /**
     * 0066: tables to choose from. All three settings, or none (off). With tables, the
     * capacity is tables x seats. A table with people on it can't be removed, and a
     * table can't get fewer seats than people already at it.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function tables(array $data, ?object $current): array
    {
        if (! array_intersect_key($data, array_flip(['seating_mode', 'table_label', 'table_count', 'table_size', 'seat_rows', 'row_names']))) {
            return $data;
        }

        // A setting sent (even empty, to switch it off) wins over the saved one.
        $pick = fn (string $key) => array_key_exists($key, $data) ? $data[$key] : ($current?->{$key} ?? null);
        $mode = $pick('seating_mode') ?: (filled($pick('table_label')) ? TableSeating::TABLES : null);
        $rows = $mode === TableSeating::ROWS;
        $label = $rows ? 'Row' : trim((string) $pick('table_label'));
        $count = (int) $pick('table_count');
        $size = (int) $pick('table_size');

        // 0067: rows of different lengths (from the stage back), and Row A or Row 1.
        $lengths = null;
        $list = $rows ? $pick('seat_rows') : null;
        if (is_string($list)) {
            $list = json_decode($list, true);
        }
        if (is_array($list) && count($list) > 1 && count(array_unique($list)) > 1) {
            $lengths = array_values(array_map(fn ($n) => min(100, max(1, (int) $n)), $list));
            $count = count($lengths);
            $size = max($lengths);
        } elseif (is_array($list) && count($list) >= 1) {
            $count = count($list) > 1 ? count($list) : $count;
            $size = (int) $list[0];
        }
        $rowNames = $rows && in_array($pick('row_names'), ['letters', 'numbers'], true) ? $pick('row_names') : null;

        $off = ['seating_mode' => null, 'table_label' => null, 'table_count' => null, 'table_size' => null, 'seat_rows' => null, 'row_names' => null];
        if ($mode === null || $label === '' || $count < 1 || $size < 1) {
            return [...$data, ...$off];
        }

        // Same layout as before: don't take away places people already have.
        if ($current !== null && TableSeating::enabled($current) && $current->seating_mode === $mode && trim($current->table_label) === $label) {
            $taken = app(TableSeating::class)->taken([$current->id])[$current->id] ?? [];
            $used = array_filter($taken);
            $lastUsed = max([0, ...array_keys($used)]);
            $what = $rows ? 'rows' : 'tables';
            if ($count < $lastUsed) {
                $name = TableSeating::groupName($current, $lastUsed);
                throw ValidationException::withMessages(['table_count' => "{$name} already has guests, so keep at least {$lastUsed} {$what}."]);
            }
            if ($rows) {
                if ($used !== [] && ($current->row_names ?? 'letters') !== ($rowNames ?? 'letters')) {
                    throw ValidationException::withMessages(['row_names' => "Seats are already sold, so the row names (A, B, C or 1, 2, 3) can't change."]);
                }
                foreach ($used as $row => $seats) {
                    $highest = max(array_keys($seats));
                    $newLength = $lengths !== null ? ($lengths[$row - 1] ?? 0) : $size;
                    if ($newLength < $highest) {
                        $name = TableSeating::groupName($current, $row);
                        throw ValidationException::withMessages(['table_size' => "{$name}, Seat {$highest} is already taken, so keep at least {$highest} seats in that row."]);
                    }
                }
            } else {
                $fullest = max([0, ...$taken]);
                if ($size < $fullest) {
                    throw ValidationException::withMessages(['table_size' => "A table already has {$fullest} guests, so keep at least {$fullest} seats per table."]);
                }
            }
        }

        return [...$data, 'seating_mode' => $mode, 'table_label' => $label, 'table_count' => $count, 'table_size' => $size,
            'seat_rows' => $lengths, 'row_names' => $rowNames, 'capacity' => $lengths !== null ? array_sum($lengths) : $count * $size];
    }

    private function assertOpen(Event $event): void
    {
        if ($event->isTerminal()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This event has ended; its tickets can no longer change.', 409);
        }
    }
}
