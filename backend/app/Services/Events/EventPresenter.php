<?php

namespace App\Services\Events;

use App\Services\Media\ImageStore;
use App\Services\Ticketing\EventGate;
use App\Models\Event;
use App\Models\EventSession;
use App\Support\Database\DatabaseContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Event payloads, batch-loaded (sessions, type names, linked client or
 * organizer) so lists stay at a fixed number of queries.
 */
class EventPresenter
{
    public function __construct(private readonly DatabaseContext $db) {}

    /**
     * Organizer view. For converted events the client already accepted this
     * organizer's quotation (B5), so their contact details are included.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, array<string, mixed>>
     */
    public function forOrganizer(Collection $events): Collection
    {
        [$sessions, $types, $progress] = $this->common($events);
        $inquiryIds = $events->pluck('inquiry_id')->filter()->unique()->values();

        $clients = $inquiryIds->isEmpty() ? collect() : $this->db->system('event.clients', fn () => DB::table('inquiries as i')
            ->join('users as u', 'u.id', '=', 'i.client_user_id')
            ->whereIn('i.id', $inquiryIds)
            ->get(['i.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone'])->keyBy('id'));

        return $events->map(fn (Event $e) => [
            ...$this->base($e, $sessions[$e->id] ?? collect(), $types, $progress[$e->id] ?? null),
            'description' => $e->description,
            'contact_name' => $e->contact_name,
            'contact_email' => $e->contact_email,
            'contact_phone' => $e->contact_phone,
            'accepted_quotation_id' => $e->accepted_quotation_id,
            'client' => ($c = $clients[$e->inquiry_id] ?? null)
                ? ['name' => trim($c->first_name.' '.$c->last_name), 'email' => $c->email, 'phone' => $c->phone]
                : null,
        ])->values();
    }

    /**
     * Client view of their own events (0034 events_client_read).
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, array<string, mixed>>
     */
    public function forClient(Collection $events): Collection
    {
        [$sessions, $types, $progress] = $this->common($events);

        $organizers = $events->isEmpty() ? collect() : $this->db->system('event.organizers', fn () => DB::table('organizations as o')
            ->leftJoin('organization_profiles as p', 'p.organization_id', '=', 'o.id')
            ->whereIn('o.id', $events->pluck('organization_id')->unique())
            ->get(['o.id', 'o.display_name', 'o.slug', 'p.contact_email', 'p.contact_phone'])->keyBy('id'));

        return $events->map(fn (Event $e) => [
            ...$this->base($e, $sessions[$e->id] ?? collect(), $types, $progress[$e->id] ?? null),
            'description' => $e->description,
            'contact_name' => $e->contact_name,
            'contact_email' => $e->contact_email,
            'contact_phone' => $e->contact_phone,
            'organizer' => ($o = $organizers[$e->organization_id] ?? null)
                ? ['display_name' => $o->display_name, 'slug' => $o->slug, 'contact_email' => $o->contact_email, 'contact_phone' => $o->contact_phone]
                : null,
        ])->values();
    }

    /**
     * @return array<string, mixed>
     */
    public static function session(EventSession $s, string $tz): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'status' => $s->status->value,
            'starts_at' => $s->starts_at->setTimezone($tz)->toIso8601String(),
            'ends_at' => $s->ends_at->setTimezone($tz)->toIso8601String(),
            // Local values for <input type="datetime-local">.
            'starts_local' => $s->starts_at->setTimezone($tz)->format('Y-m-d\TH:i'),
            'ends_local' => $s->ends_at->setTimezone($tz)->format('Y-m-d\TH:i'),
        ];
    }

    /**
     * @param  Collection<int, Event>  $events
     * @return array{0: Collection, 1: Collection, 2: Collection}
     */
    private function common(Collection $events): array
    {
        $ids = $events->pluck('id');
        $sessions = $ids->isEmpty() ? collect() : EventSession::query()->whereIn('event_id', $ids)
            ->orderBy('starts_at')->get()->groupBy('event_id');
        $types = DB::table('event_types')->whereIn('id', $events->pluck('event_type_id')->filter()->unique())->pluck('name', 'id');
        // Readiness + stage (B7). event_progress is readable by the organization and the event's client.
        $progress = $ids->isEmpty() ? collect() : DB::table('event_progress as p')
            ->leftJoin('event_stages as s', 's.id', '=', 'p.current_stage_id')
            ->whereIn('p.event_id', $ids)
            ->get(['p.event_id', 'p.readiness_percent', 'p.is_manual_override', 's.name as stage'])->keyBy('event_id');

        return [$sessions, $types, $progress];
    }

    /**
     * @param  Collection<int, EventSession>  $sessions
     * @return array<string, mixed>
     */
    private function base(Event $e, Collection $sessions, Collection $types, ?object $progress): array
    {
        $active = $sessions->filter(fn (EventSession $s) => $s->status->value !== 'cancelled');

        return [
            'id' => $e->id,
            'organization_id' => $e->organization_id,
            'name' => $e->name,
            'status' => $e->status->value,
            'event_type_id' => $e->event_type_id,
            'event_type' => $types[$e->event_type_id] ?? null,
            'timezone' => $e->timezone,
            'overall_capacity' => $e->overall_capacity,
            'venue_name' => $e->venue_name,
            'venue_address' => $e->venue_address,
            'cover_url' => ImageStore::url($e->cover_path ?? null),
            'floor_plan_url' => ImageStore::url($e->floor_plan_path ?? null), // 0066
            'latitude' => $e->latitude !== null ? (float) $e->latitude : null,
            'longitude' => $e->longitude !== null ? (float) $e->longitude : null,
            'visibility' => $e->visibility,
            // A private event's page opens only with this key (EventGate): the host shares the link.
            'private_link_key' => $e->visibility === 'private' ? EventGate::key($e->id) : null,
            'inquiry_id' => $e->inquiry_id,
            'cancelled_reason' => $e->cancelled_reason,
            'cancelled_at' => $e->cancelled_at?->toIso8601String(),
            'starts_at' => $active->first()?->starts_at->setTimezone($e->timezone)->toIso8601String(),
            'ends_at' => $active->last()?->ends_at->setTimezone($e->timezone)->toIso8601String(),
            'sessions' => $sessions->map(fn (EventSession $s) => self::session($s, $e->timezone))->values(),
            'readiness_percent' => $progress?->readiness_percent !== null ? (float) $progress->readiness_percent : null,
            'readiness_manual' => (bool) ($progress?->is_manual_override ?? false),
            'stage' => $progress?->stage,
            'created_at' => $e->created_at?->toIso8601String(),
        ];
    }
}
