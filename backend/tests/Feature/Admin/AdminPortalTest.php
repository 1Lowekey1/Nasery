<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithAuth;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** Endpoints added for the redesigned Super Admin portal (frontend merge). */
class AdminPortalTest extends TestCase
{
    use InteractsWithAuth, InteractsWithOrganizations {
        InteractsWithOrganizations::businessUser insteadof InteractsWithAuth;
    }

    public function test_client_accounts_show_counts_not_contents(): void
    {
        $admin = User::factory()->admin()->create();
        $client = User::factory()->create(['first_name' => 'Cora', 'last_name' => 'Client']);

        $this->actingAs($this->businessUser())->spaJson('GET', "/api/v1/admin/users/{$client->id}")->assertForbidden();
        $this->actingAs($admin)->spaJson('GET', "/api/v1/admin/users/{$client->id}")->assertOk()
            ->assertJsonPath('data.name', 'Cora Client')->assertJsonPath('data.inquiries', 0)->assertJsonPath('data.bookings', 0)
            ->assertJsonPath('data.tickets', 0)->assertJsonPath('data.spend', 0)->assertJsonMissingPath('data.inquiry_list');
        $row = collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/users?type=client&search=Cora')->json('data'))
            ->firstWhere('id', $client->id);
        $this->assertSame(0, $row['inquiries']);
        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/users/'.fake()->uuid())->assertNotFound();

        $overview = $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/overview')->assertOk();
        $this->assertIsInt($overview->json('data.totals.quotations_pending'));
        $this->assertGreaterThanOrEqual(1, $overview->json('data.totals.this_month.clients'));
    }

    public function test_admins_manage_the_standard_event_types(): void
    {
        $admin = User::factory()->admin()->create();
        $name = 'Reunion '.fake()->unique()->numberBetween(1000, 9999);

        $this->actingAs($this->businessUser())->spaJson('POST', '/api/v1/admin/event-types', ['name' => $name])->assertForbidden();
        $id = $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/event-types', ['name' => $name])->assertCreated()
            ->assertJsonPath('data.name', $name)->json('data.id');
        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/event-types', ['name' => strtoupper($name)])->assertUnprocessable();
        $this->assertNull(DB::table('event_types')->where('id', $id)->value('organization_id'));

        // Organizers and clients can pick it right away.
        $this->assertContains($name, collect($this->getJson('/api/v1/marketplace/event-types')->json('data'))->pluck('name'));

        $this->actingAs($admin)->spaJson('PATCH', "/api/v1/admin/event-types/{$id}", ['name' => "{$name} Party"])->assertOk()->assertJsonPath('data.name', "{$name} Party");
        $this->actingAs($admin)->spaJson('DELETE', "/api/v1/admin/event-types/{$id}")->assertOk();
        $this->assertNotContains("{$name} Party", collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/event-types')->json('data'))->pluck('name'));
        $this->actingAs($admin)->spaJson('DELETE', "/api/v1/admin/event-types/{$id}")->assertNotFound();
        $this->assertDatabaseHas('audit_logs', ['action' => 'event_type.removed', 'entity_id' => $id]);
    }
}
