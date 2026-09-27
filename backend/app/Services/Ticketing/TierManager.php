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

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Event $event, array $data): TicketType
    {
        $this->assertOpen($event);

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

    private function assertOpen(Event $event): void
    {
        if ($event->isTerminal()) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'This event has ended; its tickets can no longer change.', 409);
        }
    }
}
