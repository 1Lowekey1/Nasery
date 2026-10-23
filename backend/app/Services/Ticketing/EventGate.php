<?php

namespace App\Services\Ticketing;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which events and ticket types a guest may see and buy.
 *
 * Public events: the 0036/0050 public views (listed on the Event Marketplace).
 * Private events: the same rows, but only with the event's private link. The
 * link key is an HMAC of the event id (nothing stored, nothing to migrate):
 * whoever the organizer shares it with can open the page and get tickets,
 * while the event stays off every listing. Callers run inside system().
 */
class EventGate
{
    /** Columns and conditions of public_events, minus the visibility rule. */
    private const EVENTS_SQL = <<<'SQL'
        select e.id, e.organization_id, o.display_name as organizer_name, o.slug as organizer_slug, e.name, e.description,
            et.name as event_type, e.status, e.timezone, e.venue_name, e.venue_address, e.latitude, e.longitude,
            s.starts_at, s.ends_at, e.cover_path, e.visibility
        from events e
        join organizations o on o.id = e.organization_id
        left join event_types et on et.id = e.event_type_id
        left join lateral (
            select min(es.starts_at) as starts_at, max(es.ends_at) as ends_at from event_sessions es
            where es.event_id = e.id and es.deleted_at is null and es.status <> 'cancelled'::session_status
        ) s on true
        where e.status in ('published', 'ongoing') and e.deleted_at is null and o.deleted_at is null
            and o.approval_status = 'approved'::approval_status and not o.is_suspended
        SQL;

    public static function key(string $eventId): string
    {
        $mac = hash_hmac('sha256', 'nasery-event-link|'.strtolower($eventId), (string) config('app.key'), true);

        return rtrim(strtr(base64_encode(substr($mac, 0, 15)), '+/', '-_'), '=');
    }

    public static function opens(string $eventId, ?string $key): bool
    {
        return is_string($key) && $key !== '' && hash_equals(self::key($eventId), $key);
    }

    /** The event a guest may open: public, or private with its link. */
    public static function event(string $eventId, ?string $key): Builder
    {
        if (! self::opens($eventId, $key)) {
            return DB::table('public_events')->where('id', $eventId);
        }

        return DB::query()->fromSub(self::EVENTS_SQL, 'pe')->where('id', $eventId);
    }

    /** Its ticket types on sale (same rules as public_ticket_types). */
    public static function tiers(string $eventId, ?string $key): Builder
    {
        if (! self::opens($eventId, $key)) {
            return DB::table('public_ticket_types')->where('event_id', $eventId);
        }

        return DB::table('ticket_types')->where('event_id', $eventId)->where('status', 'on_sale')->whereNull('deleted_at')
            ->whereExists(fn ($q) => $q->fromSub(self::EVENTS_SQL, 'pe')->whereColumn('pe.id', 'ticket_types.event_id'))
            ->select(['id', 'event_id', 'name', 'description', 'zone_name', 'benefits', 'price', 'currency', 'capacity',
                'sales_start_at', 'sales_end_at', 'sort_order']);
    }
}
