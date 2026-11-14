<?php

namespace Tests\Feature\Organizations;

use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ProfileAndServicesTest extends TestCase
{
    use InteractsWithOrganizations;

    public function test_owner_updates_profile(): void
    {
        $org = $this->onboard($owner = $this->businessUser());

        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/profile", [
            'description' => 'Now also debuts.',
            'contact_phone' => '09181112222',
            'is_published' => true,
        ])->assertOk()
            ->assertJsonPath('data.description', 'Now also debuts.')
            ->assertJsonPath('data.contact_phone', '+639181112222')
            ->assertJsonPath('data.is_published', true);

        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.profile_updated', 'organization_id' => $org->id]);
    }

    public function test_cover_image_must_be_https(): void
    {
        $org = $this->onboard($owner = $this->businessUser());

        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/profile", ['cover_image_url' => 'http://insecure.test/x.png'])
            ->assertUnprocessable();
    }

    public function test_services_crud(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $base = "/api/v1/organizations/{$org->id}/services";

        $id = $this->actingAs($owner)->spaJson('POST', $base, ['custom_name' => 'Wedding Coordination', 'base_price' => 45000, 'description' => 'Full day'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Wedding Coordination')
            ->assertJsonPath('data.base_price', '45000.00')
            ->json('data.id');

        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$id}", ['base_price' => 50000.5, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.base_price', '50000.50')->assertJsonPath('data.is_active', false);

        $this->actingAs($owner)->spaJson('GET', $base)->assertOk()->assertJsonCount(1, 'data');

        $this->actingAs($owner)->spaJson('DELETE', "{$base}/{$id}")->assertOk();
        $this->actingAs($owner)->spaJson('GET', $base)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_service_validation(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $base = "/api/v1/organizations/{$org->id}/services";

        $this->actingAs($owner)->spaJson('POST', $base, ['description' => 'no name'])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('POST', $base, ['custom_name' => 'X', 'base_price' => -1])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('POST', $base, ['custom_name' => 'X', 'base_price' => 10.999])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('POST', $base, ['service_id' => (string) Str::uuid()])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['service_id']]]]);
    }

    public function test_cannot_touch_another_tenants_service_through_my_organization(): void
    {
        $mine = $this->onboard($owner = $this->businessUser());
        $theirs = $this->onboard($other = $this->businessUser());

        $theirService = $this->actingAs($other)->spaJson('POST', "/api/v1/organizations/{$theirs->id}/services", ['custom_name' => 'Catering'])
            ->assertCreated()->json('data.id');

        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$mine->id}/services/{$theirService}", ['custom_name' => 'Stolen'])
            ->assertNotFound();
        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$mine->id}/services/{$theirService}")
            ->assertNotFound();
    }

    public function test_removing_a_service_moves_its_price_options_to_add_ons(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $service = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/services", ['custom_name' => 'Catering'])
            ->assertCreated()->json('data.id');
        $option = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/price-options", [
            'name' => 'Buffet', 'pricing_unit' => 'per_guest', 'price' => 500, 'organization_service_id' => $service,
        ])->assertCreated()->json('data.id');

        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/services/{$service}")->assertOk();

        $row = collect($this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/price-options")->assertOk()->json('data'))
            ->firstWhere('id', $option);
        $this->assertNotNull($row);
        $this->assertNull($row['organization_service_id']);
    }

    public function test_catalog_is_readable_by_verified_users(): void
    {
        $this->actingAs($this->businessUser())->spaJson('GET', '/api/v1/services')->assertOk()->assertJsonIsArray('data');
    }
}
