<?php

namespace Tests\Feature\Organizations;

use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class EventTypesTest extends TestCase
{
    use InteractsWithOrganizations;

    public function test_owner_manages_custom_event_types_alongside_platform_defaults(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $base = "/api/v1/organizations/{$org->id}/event-types";

        $id = $this->actingAs($owner)->spaJson('POST', $base, ['name' => '  Product   Launch ', 'description' => 'Brand launches'])
            ->assertCreated()->assertJsonPath('data.name', 'Product Launch')->assertJsonPath('data.is_platform_default', false)
            ->json('data.id');

        $list = collect($this->actingAs($owner)->spaJson('GET', $base)->assertOk()->json('data'));
        $this->assertTrue($list->contains(fn ($t) => $t['name'] === 'Wedding' && $t['is_platform_default']));
        $this->assertTrue($list->contains('id', $id));

        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$id}", ['name' => 'Store Opening'])->assertOk()->assertJsonPath('data.name', 'Store Opening');
        $this->actingAs($owner)->spaJson('DELETE', "{$base}/{$id}")->assertOk();
        $this->assertNotNull(DB::table('event_types')->where('id', $id)->value('deleted_at'));
    }

    public function test_names_must_be_unique_including_platform_defaults(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $base = "/api/v1/organizations/{$org->id}/event-types";

        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'wedding'])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Reunion'])->assertCreated();
        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'REUNION'])->assertUnprocessable();
    }

    public function test_platform_defaults_and_other_tenants_types_cannot_be_edited(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $other = $this->onboard($otherOwner = $this->businessUser());
        $theirs = $this->actingAs($otherOwner)->spaJson('POST', "/api/v1/organizations/{$other->id}/event-types", ['name' => 'Private Type'])->json('data.id');
        $wedding = DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id');

        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/event-types/{$wedding}", ['name' => 'Mine'])->assertNotFound();
        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/event-types/{$theirs}")->assertNotFound();
        $this->assertSame('Wedding', DB::table('event_types')->where('id', $wedding)->value('name'));
    }

    public function test_staff_cannot_manage_event_types(): void
    {
        $org = $this->onboard($this->businessUser());
        $this->addStaff($org, $staff = $this->businessUser());

        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/event-types")->assertOk();
        $this->actingAs($staff)->spaJson('POST', "/api/v1/organizations/{$org->id}/event-types", ['name' => 'Staff Type'])->assertForbidden();
    }
}
