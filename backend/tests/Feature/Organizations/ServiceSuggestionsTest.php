<?php

namespace Tests\Feature\Organizations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** Services not in the catalog: the organizer's own right away, and a suggestion admins decide (0056). */
class ServiceSuggestionsTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @return list<string> the organization's live services, catalog name or custom name */
    private function offered(string $orgId): array
    {
        return DB::table('organization_services as os')->leftJoin('services as s', 's.id', '=', 'os.service_id')
            ->where('os.organization_id', $orgId)->whereNull('os.deleted_at')
            ->pluck(DB::raw('coalesce(s.name, os.custom_name) as n'))->sort()->values()->all();
    }

    public function test_catalog_names_are_reused_new_ones_are_usable_right_away_and_admins_decide(): void
    {
        [$a, $ownerA] = $this->listedOrganizer('Service A');
        [$b, $ownerB] = $this->listedOrganizer('Service B');
        $admin = $this->adminUser();
        $url = fn ($org) => "/api/v1/organizations/{$org->id}/service-suggestions";
        $catering = DB::table('services')->whereNull('deleted_at')->where('name', 'Catering')->value('name');
        $this->assertNotNull($catering);
        $name = 'Drone Show '.random_int(1000, 9999); // unique across runs on the shared database

        // Already in the catalog (any capitals): they offer that one, once. A typo asks "Did you mean …?".
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => '  cATERING ', 'base_price' => 500])->assertCreated()
            ->assertJsonPath('data.result', 'exists')->assertJsonPath('data.service', 'Catering');
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => 'Catering'])->assertOk()->assertJsonPath('data.offered', false);
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => 'Caterng'])->assertOk()
            ->assertJsonPath('data.result', 'similar')->assertJsonPath('data.service', 'Catering');

        // New: their own service at once, and one row for the admins even when two organizers ask.
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => $name, 'base_price' => 15000])->assertCreated()->assertJsonPath('data.result', 'pending');
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => mb_strtoupper($name)])->assertOk()->assertJsonPath('data.result', 'already_suggested');
        $this->actingAs($ownerB)->spaJson('POST', $url($b), ['name' => strtolower($name)])->assertCreated();
        $this->assertContains($name, $this->offered($a->id));
        $this->assertSame(1, DB::table('organization_services')->where('organization_id', $a->id)->whereRaw('lower(custom_name) = lower(?)', [$name])->whereNull('deleted_at')->count());

        $row = collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/service-suggestions')->assertOk()->json('data'))
            ->first(fn ($r) => strcasecmp($r['name'], $name) === 0);
        $this->assertCount(2, $row['organizations']);
        $this->actingAs($ownerA)->spaJson('POST', "/api/v1/admin/service-suggestions/{$row['id']}/approve")->assertForbidden();
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/service-suggestions/{$row['id']}/reject")->assertUnprocessable();

        // A price option on A's custom service survives the approval.
        $customId = DB::table('organization_services')->where('organization_id', $a->id)->whereRaw('lower(custom_name) = lower(?)', [$name])->value('id');
        $this->actingAs($ownerA)->spaJson('POST', "/api/v1/organizations/{$a->id}/price-options",
            ['name' => 'Drone show, 10 minutes', 'organization_service_id' => $customId, 'pricing_unit' => 'flat', 'price' => 15000])->assertCreated();

        // Approved with a category: a catalog service; both organizers' custom ones now point at it.
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/service-suggestions/{$row['id']}/approve", ['category' => 'Entertainment'])->assertOk();
        $serviceId = DB::table('services')->whereNull('deleted_at')->where('name', $name)->value('id');
        $this->assertNotNull($serviceId);
        $this->assertSame($serviceId, DB::table('organization_services')->where('id', $customId)->value('service_id'));
        $this->assertSame($customId, DB::table('organization_price_options')->where('organization_id', $a->id)->where('name', 'Drone show, 10 minutes')->value('organization_service_id'));
        $this->assertTrue(DB::table('organization_services')->where('organization_id', $b->id)->where('service_id', $serviceId)->whereNull('deleted_at')->exists());
        $this->assertSame(1, DB::table('notifications')->where('type', 'service.decided')->where('recipient_user_id', $ownerB->id)->count());
        $this->actingAs($ownerA)->spaJson('GET', $url($a))->assertOk()->assertJsonPath('data.0.status', 'approved')->assertJsonPath('data.0.service', $name);

        // Rejected: it stays theirs.
        $kept = 'Fire Dancers '.random_int(1000, 9999);
        $this->actingAs($ownerB)->spaJson('POST', $url($b), ['name' => $kept, 'confirm_new' => true])->assertCreated();
        $reject = collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/service-suggestions')->json('data'))->firstWhere('name', $kept);
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/service-suggestions/{$reject['id']}/reject", ['note' => 'Too specific for the catalog.'])->assertOk();
        $this->assertContains($kept, $this->offered($b->id));
        $this->assertFalse(DB::table('services')->where('name', $kept)->exists());
    }
}
