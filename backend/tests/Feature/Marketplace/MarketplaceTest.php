<?php

namespace Tests\Feature\Marketplace;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use InteractsWithOrganizations;

    /** Onboard, approve and publish an organizer (as the admin flow would). */
    private function listed(string $name, array $profile = [], array $services = []): Organization
    {
        $owner = $this->businessUser();
        $org = $this->onboard($owner, ['display_name' => $name.' '.Str::random(4), 'profile' => $profile]);

        foreach ($services as $service) {
            $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/services", ['custom_name' => $service, 'base_price' => 15000]);
        }

        DB::table('organizations')->where('id', $org->id)->update(['approval_status' => 'approved']);
        DB::table('organization_profiles')->where('organization_id', $org->id)->update(['is_published' => true]);

        return $org->refresh();
    }

    private function ids(array $response): array
    {
        return array_column($response['data'], 'id');
    }

    public function test_guests_see_only_approved_published_organizers(): void
    {
        $listed = $this->listed('Visible Events');
        $pending = $this->onboard($this->businessUser());
        $unpublished = $this->listed('Hidden Events');
        DB::table('organization_profiles')->where('organization_id', $unpublished->id)->update(['is_published' => false]);
        $suspended = $this->listed('Suspended Events');
        DB::table('organizations')->where('id', $suspended->id)->update([
            'approval_status' => 'suspended', 'is_suspended' => true, 'suspended_reason' => 'x', 'suspended_at' => now(), 'suspended_by' => $this->adminUser()->id,
        ]);

        $ids = $this->ids($this->getJson('/api/v1/marketplace/organizers?per_page=48')->assertOk()->json());

        $this->assertContains($listed->id, $ids);
        $this->assertNotContains($pending->id, $ids);
        $this->assertNotContains($unpublished->id, $ids);
        $this->assertNotContains($suspended->id, $ids);

        $this->getJson("/api/v1/marketplace/organizers/{$pending->slug}")->assertNotFound();
        $this->getJson("/api/v1/marketplace/organizers/{$suspended->slug}")->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_cards_expose_only_public_fields(): void
    {
        $org = $this->listed('Public Fields Co', services: ['Wedding Planning']);

        $card = collect($this->getJson('/api/v1/marketplace/organizers?search='.urlencode($org->display_name))->json('data'))->firstWhere('id', $org->id);

        $this->assertSame($org->slug, $card['slug']);
        $this->assertSame(['Wedding Planning'], $card['services']);
        $this->assertNull($card['rating_avg']);
        $this->assertSame(0, $card['reviews_count']);
        foreach (['legal_name', 'approval_status', 'is_suspended', 'contact_email'] as $private) {
            $this->assertArrayNotHasKey($private, $card);
        }
    }

    public function test_search_matches_names_and_services(): void
    {
        $marker = 'Zq'.Str::random(6);
        $byName = $this->listed("{$marker} Planners");
        $byService = $this->listed('Other Co', services: ["{$marker} Catering"]);
        $unrelated = $this->listed('Unrelated Co', services: ['Lights']);

        $ids = $this->ids($this->getJson('/api/v1/marketplace/organizers?search='.$marker)->assertOk()->json());
        $this->assertEqualsCanonicalizing([$byName->id, $byService->id], array_values(array_intersect($ids, [$byName->id, $byService->id, $unrelated->id])));

        $ids = $this->ids($this->getJson('/api/v1/marketplace/organizers?service='.urlencode("{$marker} Catering"))->json());
        $this->assertSame([$byService->id], $ids);
    }

    public function test_wildcards_in_search_are_literal(): void
    {
        $this->listed('Percent Co');

        $this->getJson('/api/v1/marketplace/organizers?search='.urlencode('%_%'))
            ->assertOk()->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_near_me_filters_and_sorts_by_distance(): void
    {
        $marker = 'Geo'.Str::random(5);
        $davao = $this->listed("{$marker} Davao", ['latitude' => 7.0731, 'longitude' => 125.6128]);
        $digos = $this->listed("{$marker} Digos", ['latitude' => 6.7497, 'longitude' => 125.3572]);
        $manila = $this->listed("{$marker} Manila", ['latitude' => 14.5995, 'longitude' => 120.9842]);

        $response = $this->getJson("/api/v1/marketplace/organizers?search={$marker}&lat=7.0707&lng=125.6087&radius_km=60")->assertOk();
        $ids = $this->ids($response->json());

        $this->assertSame([$davao->id, $digos->id], $ids);
        $this->assertLessThan(1, $response->json('data.0.distance_km'));
        $this->assertEqualsWithDelta(45, $response->json('data.1.distance_km'), 10);
        $this->assertNotContains($manila->id, $ids);
    }

    public function test_filter_validation(): void
    {
        $this->getJson('/api/v1/marketplace/organizers?lat=7.1')->assertUnprocessable();
        $this->getJson('/api/v1/marketplace/organizers?lat=100&lng=0')->assertUnprocessable();
        $this->getJson('/api/v1/marketplace/organizers?radius_km=5000&lat=7&lng=125')->assertUnprocessable();
        $this->getJson('/api/v1/marketplace/organizers?sort=hacked')->assertUnprocessable();
    }

    public function test_profile_page_by_slug(): void
    {
        $org = $this->listed('Profile Page Co', ['description' => 'We plan debuts.'], ['Debut Styling']);
        $owner = $org->members()->where('is_owner', true)->first()->user;
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/event-types", ['name' => 'Pet Birthday Bash'])->assertCreated();
        $inactive = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/services", ['custom_name' => 'Retired Service', 'is_active' => false])->json('data.id');

        $this->app['auth']->forgetGuards();
        $profile = $this->getJson("/api/v1/marketplace/organizers/{$org->slug}")->assertOk()->json('data');

        $this->assertSame('We plan debuts.', $profile['description']);
        $this->assertSame(['Debut Styling'], array_column($profile['services'], 'name'));
        $this->assertNotContains($inactive, array_column($profile['services'], 'id'));
        $this->assertSame(['Pet Birthday Bash'], array_column($profile['event_types'], 'name'));
        $this->assertSame(0, $profile['rating']['count']);
        $this->assertSame([], $profile['recent_reviews']);
        $this->assertArrayNotHasKey('legal_name', $profile);

        $this->getJson("/api/v1/marketplace/organizers/{$org->slug}/reviews")->assertOk()->assertJsonPath('meta.pagination.total', 0);
        $this->getJson('/api/v1/marketplace/organizers/no-such-organizer/reviews')->assertNotFound();
    }

    public function test_platform_event_types_and_service_names(): void
    {
        $this->getJson('/api/v1/marketplace/event-types')->assertOk()->assertJsonFragment(['name' => 'Wedding']);

        $marker = 'Chip'.Str::random(5);
        $this->listed('Chips Co', services: ["{$marker} Hosting"]);
        $this->getJson('/api/v1/marketplace/services')->assertOk()->assertJsonFragment(['name' => "{$marker} Hosting", 'organizers' => 1]);
    }
}
