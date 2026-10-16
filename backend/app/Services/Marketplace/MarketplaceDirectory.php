<?php

namespace App\Services\Marketplace;

use App\Support\Database\DatabaseContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The public marketplace (B3). Reads ONLY the four controlled public views
 * (public_organization_profiles / _services / public_event_types /
 * public_reviews), which already restrict rows to published, approved,
 * non-suspended organizers and columns to public fields. The views are
 * security_invoker and guests have no base-table access, so reads run in the
 * system context, but never against base tables.
 */
class MarketplaceDirectory
{
    public const MAX_RADIUS_KM = 200;

    public function __construct(private readonly DatabaseContext $db) {}

    /**
     * @param  array{search?: ?string, service?: ?string, lat?: ?float, lng?: ?float, radius_km?: ?float, sort?: ?string, page?: int, per_page?: int}  $filters
     */
    public function organizers(array $filters): LengthAwarePaginator
    {
        return $this->db->system('marketplace.directory', function () use ($filters) {
            $near = isset($filters['lat'], $filters['lng']);
            $query = DB::table('public_organization_profiles as p')
                ->leftJoinSub($this->ratings(), 'r', 'r.organization_id', '=', 'p.organization_id')
                ->select([
                    'p.organization_id', 'p.slug', 'p.display_name', 'p.business_name', 'p.description',
                    'p.location_text', 'p.cover_image_url', 'p.latitude', 'p.longitude',
                    DB::raw('coalesce(r.rating_avg, 0) as rating_avg'),
                    DB::raw('coalesce(r.reviews_count, 0) as reviews_count'),
                ]);

            if (filled($filters['search'] ?? null)) {
                $term = $this->like($filters['search']);
                $query->where(fn (Builder $q) => $q
                    ->where('p.display_name', 'ilike', $term)
                    ->orWhere('p.business_name', 'ilike', $term)
                    ->orWhere('p.description', 'ilike', $term)
                    ->orWhere('p.location_text', 'ilike', $term)
                    ->orWhereExists(fn (Builder $s) => $s->from('public_organization_services as s')
                        ->whereColumn('s.organization_id', 'p.organization_id')
                        ->where('s.name', 'ilike', $term)));
            }

            if (filled($filters['service'] ?? null)) {
                $query->whereExists(fn (Builder $s) => $s->from('public_organization_services as s')
                    ->whereColumn('s.organization_id', 'p.organization_id')
                    ->where('s.name', 'ilike', $this->like($filters['service'])));
            }

            if ($near) {
                $distance = $this->distanceSql();
                $bindings = [$filters['lat'], $filters['lng'], $filters['lat']];
                $query->addSelect(DB::raw("{$distance} as distance_km"))->addBinding($bindings, 'select')
                    ->whereNotNull('p.latitude')->whereNotNull('p.longitude')
                    ->whereRaw("{$distance} <= ?", [...$bindings, min((float) ($filters['radius_km'] ?? 25), self::MAX_RADIUS_KM)]);
            }

            match ($filters['sort'] ?? ($near ? 'distance' : 'rating')) {
                'distance' => $near ? $query->orderBy('distance_km') : $query->orderByDesc('rating_avg'),
                'name' => $query->orderBy('p.display_name'),
                default => $query->orderByDesc('rating_avg')->orderByDesc('reviews_count'),
            };
            $query->orderBy('p.display_name')->orderBy('p.organization_id');

            $page = $query->paginate(perPage: $filters['per_page'] ?? 12, page: $filters['page'] ?? 1);
            $ids = $page->getCollection()->pluck('organization_id');
            $services = $this->serviceNames($ids);
            $completed = $this->completedCounts($ids);

            $page->getCollection()->transform(fn (object $row) => $this->card($row, $services[$row->organization_id] ?? collect()) + [
                'completed_events' => (int) ($completed[$row->organization_id] ?? 0),
            ]);

            return $page;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function organizer(string $slug): ?array
    {
        return $this->db->system('marketplace.profile', function () use ($slug) {
            $profile = DB::table('public_organization_profiles')->where('slug', $slug)->first();

            if ($profile === null) {
                return null;
            }

            $ratings = DB::table('public_reviews')->where('organization_id', $profile->organization_id)
                ->selectRaw('rating, count(*) as total')->groupBy('rating')->pluck('total', 'rating');
            $count = (int) $ratings->sum();

            return [
                'id' => $profile->organization_id,
                'slug' => $profile->slug,
                'display_name' => $profile->display_name,
                'business_name' => $profile->business_name,
                'description' => $profile->description,
                'location_text' => $profile->location_text,
                'latitude' => $profile->latitude !== null ? (float) $profile->latitude : null,
                'longitude' => $profile->longitude !== null ? (float) $profile->longitude : null,
                'contact_email' => $profile->contact_email,
                'contact_phone' => $profile->contact_phone,
                'cover_image_url' => $profile->cover_image_url,
                // 0046: details from the business profile panel.
                'years_experience' => $profile->years_experience ?? null,
                'service_radius_km' => isset($profile->service_radius_km) ? (int) $profile->service_radius_km : null,
                'event_tags' => isset($profile->event_tags) ? json_decode($profile->event_tags, true) : [],
                'specialties' => isset($profile->specialties) ? json_decode($profile->specialties, true) : [],
                'website' => $profile->website ?? null,
                'facebook' => $profile->facebook ?? null,
                'instagram' => $profile->instagram ?? null,
                'banner_color' => $profile->banner_color ?? null,
                // 0048: estimate settings for the client calculator.
                'estimate' => [
                    'service_charge_percent' => isset($profile->estimate_service_charge_percent) ? (float) $profile->estimate_service_charge_percent : null,
                    'tax_percent' => isset($profile->estimate_tax_percent) ? (float) $profile->estimate_tax_percent : null,
                    'note' => $profile->estimate_note ?? null,
                ],
                'services' => DB::table('public_organization_services')->where('organization_id', $profile->organization_id)
                    ->orderBy('name')->get(['id', 'name', 'description', 'base_price'])
                    ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'base_price' => $s->base_price])->all(),
                // Quotation calculator (0033): the organizer's own price list.
                'price_options' => DB::table('public_organization_price_options')->where('organization_id', $profile->organization_id)
                    ->orderBy('sort_order')->orderBy('name')
                    ->get(['id', 'organization_service_id', 'name', 'description', 'pricing_unit', 'price', 'min_guests', 'unit_label', 'min_quantity', 'max_quantity'])
                    ->map(fn ($o) => (array) $o)->all(),
                'event_types' => DB::table('public_event_types')->where('organization_id', $profile->organization_id)
                    ->orderBy('name')->get(['id', 'name', 'description'])->map(fn ($t) => (array) $t)->all(),
                'rating' => [
                    'average' => $count ? round($ratings->reduce(fn ($sum, $total, $rating) => $sum + $rating * $total, 0) / $count, 2) : null,
                    'count' => $count,
                    'distribution' => collect(range(5, 1))->mapWithKeys(fn ($r) => [$r => (int) ($ratings[$r] ?? 0)]),
                ],
                'recent_reviews' => $this->reviewQuery($profile->organization_id)->limit(5)->get()->map(fn ($r) => $this->review($r))->all(),
                'completed_events' => (int) ($this->completedCounts(collect([$profile->organization_id]))[$profile->organization_id] ?? 0),
                'portfolio' => $this->portfolio($profile->organization_id),
            ];
        });
    }

    public function reviews(string $slug, int $page): ?LengthAwarePaginator
    {
        return $this->db->system('marketplace.reviews', function () use ($slug, $page) {
            $organizationId = DB::table('public_organization_profiles')->where('slug', $slug)->value('organization_id');

            if ($organizationId === null) {
                return null;
            }

            $paginator = $this->reviewQuery($organizationId)->paginate(perPage: 10, page: $page);
            $paginator->getCollection()->transform(fn ($r) => $this->review($r));

            return $paginator;
        });
    }

    /**
     * Platform-default event types (for filters and inquiry forms).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function platformEventTypes(): Collection
    {
        return $this->db->system('marketplace.event_types', fn () => DB::table('public_event_types')
            ->whereNull('organization_id')->orderBy('name')->get(['id', 'name', 'description'])->map(fn ($t) => (array) $t));
    }

    /**
     * Service names offered by listed organizers, most common first (filter chips).
     *
     * @return Collection<int, array{name: string, organizers: int}>
     */
    public function serviceNames(?Collection $organizationIds = null): Collection
    {
        if ($organizationIds !== null) {
            if ($organizationIds->isEmpty()) {
                return collect();
            }

            return DB::table('public_organization_services')->whereIn('organization_id', $organizationIds)
                ->orderBy('name')->get(['organization_id', 'name'])
                ->groupBy('organization_id')->map(fn ($rows) => $rows->pluck('name')->unique()->values());
        }

        return $this->db->system('marketplace.service_names', fn () => DB::table('public_organization_services as s')
            ->join('public_organization_profiles as p', 'p.organization_id', '=', 's.organization_id')
            ->selectRaw('s.name, count(distinct s.organization_id) as organizers')
            ->groupBy('s.name')->orderByDesc('organizers')->orderBy('s.name')->limit(40)->get()
            ->map(fn ($r) => ['name' => $r->name, 'organizers' => (int) $r->organizers]));
    }

    private function ratings(): Builder
    {
        return DB::table('public_reviews')
            ->selectRaw('organization_id, round(avg(rating)::numeric, 2) as rating_avg, count(*) as reviews_count')
            ->groupBy('organization_id');
    }

    private function reviewQuery(string $organizationId): Builder
    {
        return DB::table('public_reviews')->where('organization_id', $organizationId)->orderByDesc('created_at')
            ->select(['id', 'rating', 'written_review', 'created_at', 'reviewer_first_name', 'reviewer_last_initial', 'event_type_name', 'response_text', 'responded_at', 'is_edited']);
    }

    /**
     * @return array<string, mixed>
     */
    private function review(object $r): array
    {
        return [
            'id' => $r->id,
            'rating' => (int) $r->rating,
            'written_review' => $r->written_review,
            'reviewer' => trim($r->reviewer_first_name.' '.$r->reviewer_last_initial.'.'),
            'event_type' => $r->event_type_name,
            'created_at' => $r->created_at,
            'edited' => (bool) $r->is_edited,
            // B16 (0038): the organizer's public reply.
            'reply' => $r->response_text ? ['text' => $r->response_text, 'at' => $r->responded_at] : null,
        ];
    }

    /**
     * @param  Collection<int, string>  $services
     * @return array<string, mixed>
     */
    private function card(object $row, Collection $services): array
    {
        return [
            'id' => $row->organization_id,
            'slug' => $row->slug,
            'display_name' => $row->display_name,
            'business_name' => $row->business_name,
            'excerpt' => $row->description ? mb_strimwidth($row->description, 0, 180, '…') : null,
            'location_text' => $row->location_text,
            'cover_image_url' => $row->cover_image_url,
            'latitude' => $row->latitude !== null ? (float) $row->latitude : null,
            'longitude' => $row->longitude !== null ? (float) $row->longitude : null,
            'rating_avg' => (int) $row->reviews_count ? (float) $row->rating_avg : null,
            'reviews_count' => (int) $row->reviews_count,
            'services' => $services->take(6)->values()->all(),
            'distance_km' => isset($row->distance_km) ? round((float) $row->distance_km, 1) : null,
        ];
    }

    /** @return Collection<string, int> completed (not deleted) events per organization */
    private function completedCounts(Collection $organizationIds): Collection
    {
        return DB::table('events')->whereIn('organization_id', $organizationIds)->where('status', 'completed')->whereNull('deleted_at')
            ->groupBy('organization_id')->selectRaw('organization_id, count(*) as total')->pluck('total', 'organization_id');
    }

    /**
     * Past work for the public profile. Public (ticketed) events are already
     * public, so they show in full. Private client events show only their type
     * and month: never the name, venue or anything that identifies the client.
     *
     * @return list<array<string, mixed>>
     */
    private function portfolio(string $organizationId): array
    {
        return DB::table('events as e')
            ->leftJoin('event_types as t', 't.id', '=', 'e.event_type_id')
            ->where('e.organization_id', $organizationId)->where('e.status', 'completed')->whereNull('e.deleted_at')
            ->selectRaw('e.id, e.name, e.description, e.venue_name, e.visibility, t.name as type,
                (select min(s.starts_at) from event_sessions s where s.event_id = e.id and s.deleted_at is null) as held_at')
            ->orderByDesc('held_at')->limit(12)->get()
            ->map(function (object $e) {
                $public = $e->visibility === 'public';

                return [
                    'id' => $public ? $e->id : null,
                    'title' => $public ? $e->name : ($e->type ?? 'Private event'),
                    'category' => $e->type ?? 'Event',
                    'date' => $e->held_at ? substr((string) $e->held_at, 0, 10) : null,
                    'venue' => $public ? $e->venue_name : null,
                    'description' => $public ? $e->description : null,
                    'is_public' => $public,
                ];
            })->all();
    }

    /** Great-circle distance in km (haversine) from (?, ?) to the profile. */
    private function distanceSql(): string
    {
        return '(6371 * acos(least(1, greatest(-1,
            cos(radians(?)) * cos(radians(p.latitude)) * cos(radians(p.longitude) - radians(?))
            + sin(radians(?)) * sin(radians(p.latitude))))))';
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], trim($value)).'%';
    }
}
