<?php

namespace App\Services\Inquiries;

use App\Models\Inquiry;
use App\Models\Service;
use App\Support\Database\DatabaseContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who may receive an inquiry. Reads only the public marketplace views, so
 * only LISTED organizers (published + approved + not suspended) can ever be
 * invited, directly or by matching.
 *
 * Open-posting match (approved B4 rule): the organizer offers at least one
 * requested service (service names that contain each other, case-insensitive;
 * catalog services compare by catalog name) AND their profile pin is within the inquiry's
 * match_radius_km of the inquiry's pin AND (advisor review, 2026-11) the event
 * type is one of their profile's event tags. Organizers without event tags
 * aren't matched until they add them.
 */
class InquiryMatcher
{
    /** Safety cap on invitations created by one open posting. */
    public const MAX_OPEN_POSTING_INVITES = 100;

    public const MAX_DIRECT_INVITES = 10;

    public function __construct(private readonly DatabaseContext $db) {}

    /**
     * @param  list<string>  $organizationIds
     * @return Collection<int, string> the subset that is currently listed
     */
    public function listed(array $organizationIds): Collection
    {
        if ($organizationIds === []) {
            return collect();
        }

        return $this->db->system('inquiry.listed_check', fn () => DB::table('public_organization_profiles')
            ->whereIn('organization_id', $organizationIds)->pluck('organization_id'));
    }

    /**
     * @return Collection<int, object{organization_id: string, slug: string, display_name: string, distance_km: float}>
     */
    public function matches(Inquiry $inquiry, int $limit = self::MAX_OPEN_POSTING_INVITES): Collection
    {
        $wanted = $inquiry->services()->get(['service_id', 'custom_service_name']);

        if ($inquiry->latitude === null || $inquiry->match_radius_km === null || $wanted->isEmpty()) {
            return collect();
        }

        $lat = (float) $inquiry->latitude;
        $lng = (float) $inquiry->longitude;
        $radius = (float) $inquiry->match_radius_km;
        // Catalog services are matched by their catalog name (platform data), so
        // tenant data is only ever read through the public views.
        $catalogIds = $wanted->pluck('service_id')->filter()->values();
        $catalogNames = $catalogIds->isEmpty() ? collect() : $this->db->system('inquiry.catalog', fn () => Service::query()->whereIn('id', $catalogIds)->pluck('name'));
        $names = $wanted->pluck('custom_service_name')->merge($catalogNames)->filter()
            ->map(fn ($n) => mb_strtolower(trim($n)))->unique()->values()->all();
        // The event type's name, compared with the organizer's event tags (names too).
        $eventType = $inquiry->event_type_id === null ? null : $this->db->system('inquiry.event_type',
            fn () => DB::table('event_types')->where('id', $inquiry->event_type_id)->value('name'));
        $eventType = $eventType !== null ? mb_strtolower(trim($eventType)) : null;

        return $this->db->system('inquiry.match', function () use ($lat, $lng, $radius, $names, $limit, $eventType) {
            $distance = '(6371 * acos(least(1, greatest(-1,
                cos(radians(?)) * cos(radians(p.latitude)) * cos(radians(p.longitude) - radians(?))
                + sin(radians(?)) * sin(radians(p.latitude))))))';

            return DB::table('public_organization_profiles as p')
                ->select(['p.organization_id', 'p.slug', 'p.display_name'])
                ->selectRaw("{$distance} as distance_km", [$lat, $lng, $lat])
                ->whereNotNull('p.latitude')->whereNotNull('p.longitude')
                ->whereRaw("{$distance} <= ?", [$lat, $lng, $lat, $radius])
                ->when($eventType !== null, fn ($q) => $q->whereRaw(
                    'exists (select 1 from jsonb_array_elements_text(p.event_tags) as tag where lower(trim(tag)) = ?)', [$eventType]))
                ->whereExists(function (Builder $q) use ($names) {
                    $q->from('public_organization_services as s')
                        ->whereColumn('s.organization_id', 'p.organization_id')
                        ->where(function (Builder $w) use ($names) {
                            // Exact (case-insensitive) match, or containment either
                            // way for names of 4+ characters ("Wedding" ~ "Wedding
                            // coordination"); short names like "DJ" must match exactly.
                            foreach ($names as $name) {
                                $w->orWhereRaw('lower(s.name) = ?', [$name])
                                    ->orWhereRaw('(length(s.name) >= 4 and position(lower(s.name) in ?) > 0)', [$name])
                                    ->orWhereRaw('(length(?) >= 4 and position(? in lower(s.name)) > 0)', [$name, $name]);
                            }
                        });
                })
                ->orderBy('distance_km')
                ->limit($limit)
                ->get();
        });
    }
}
