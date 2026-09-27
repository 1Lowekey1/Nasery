<?php

namespace App\Services\Analytics;

use App\Models\Event;
use App\Models\Organization;
use App\Services\CheckIn\CheckInDesk;
use App\Services\CheckIn\SessionWindow;
use App\Services\CheckIn\WalkInDesk;
use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * B15 live event-day analytics (paper: real-time check-in rate, walk-in vs
 * registered ratio, capacity fill). "Live" = the door is open for a
 * session right now (B11 window) or the event is marked ongoing. Figures
 * come from the same services the door uses, so they always agree.
 */
class LiveEvents
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly SessionWindow $window,
        private readonly CheckInDesk $checkIn,
        private readonly WalkInDesk $walkIns,
    ) {}

    /**
     * @param  callable(Event): bool  $visible  events this member may see
     * @return array{live: list<array<string, mixed>>, upcoming: list<array<string, mixed>>}
     */
    public function forOrganization(Organization $org, callable $visible): array
    {
        $events = $this->db->system('analytics.live.events', fn () => Event::query()->where('organization_id', $org->id)
            ->whereIn('status', ['published', 'ongoing'])->whereNull('deleted_at')->get())->filter($visible);

        $live = [];
        $upcoming = [];
        foreach ($events as $event) {
            $sessions = $this->window->sessions($event);
            $open = $sessions->first(fn ($s) => $this->window->isOpen($s));

            if ($open !== null || $event->status->value === 'ongoing') {
                $live[] = $this->snapshot($event, $open ?? $sessions->first());

                continue;
            }

            $next = $sessions->first(fn ($s) => CarbonImmutable::parse($s->starts_at)->isFuture());
            if ($next !== null && CarbonImmutable::parse($next->starts_at)->lte(now()->addDays(7))) {
                $upcoming[] = ['event_id' => $event->id, 'name' => $event->name, ...$this->window->present($next, $event)];
            }
        }

        usort($upcoming, fn ($a, $b) => strcmp($a['starts_at'], $b['starts_at']));

        return ['live' => $live, 'upcoming' => array_slice($upcoming, 0, 5)];
    }

    /** @return array<string, mixed> */
    private function snapshot(Event $event, ?object $session): array
    {
        $summary = $this->checkIn->summary($event, 6);
        $capacity = $this->db->system('analytics.live.capacity', fn () => $this->walkIns->capacityState($event));
        $since = now()->subMinutes(15);
        $recentArrivals = $this->db->system('analytics.live.pace', fn () => DB::table('attendance_records')->where('event_id', $event->id)->where('checked_in_at', '>=', $since)->count()
            + (int) DB::table('walk_in_records')->where('event_id', $event->id)->whereNull('linked_ticket_id')->where('recorded_at', '>=', $since)->sum('count'));

        return [
            'event_id' => $event->id,
            'name' => $event->name,
            'status' => $event->status->value,
            'session' => $session ? $this->window->present($session, $event) : null,
            'expected' => $summary['expected'],
            'checked_in' => $summary['checked_in'],
            'walk_ins' => $summary['walk_ins'],
            'arrived' => $summary['arrived'],
            'check_in_rate' => $summary['expected'] > 0 ? round($summary['checked_in'] * 100 / $summary['expected'], 1) : null,
            'walk_in_ratio' => $summary['arrived'] > 0 ? round($summary['walk_ins'] * 100 / $summary['arrived'], 1) : null,
            // Booked capacity (tickets + walk-ins, what the door enforces) and who is actually inside now.
            'capacity' => $capacity,
            'occupancy' => $capacity['limit'] ? round($summary['arrived'] * 100 / $capacity['limit'], 1) : null,
            'arrivals_last_15_min' => $recentArrivals,
            'recent' => array_map(fn ($r) => collect($r)->only(['name', 'tier', 'method', 'checked_in_at'])->all(), $summary['recent']),
            'tiers' => $summary['tiers'],
        ];
    }
}
