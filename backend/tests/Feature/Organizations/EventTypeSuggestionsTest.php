<?php

namespace Tests\Feature\Organizations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** Organizers suggest event types; obvious duplicates and typos are caught, the rest admins decide. */
class EventTypeSuggestionsTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function tags(string $orgId): array
    {
        return json_decode((string) DB::table('organization_profiles')->where('organization_id', $orgId)->value('event_tags'), true) ?: [];
    }

    public function test_duplicates_and_typos_are_caught_and_admins_decide_the_rest(): void
    {
        [$a, $ownerA] = $this->listedOrganizer('Suggest A');
        [$b, $ownerB] = $this->listedOrganizer('Suggest B');
        $admin = $this->adminUser();
        $url = fn ($org) => "/api/v1/organizations/{$org->id}/event-type-suggestions";
        $name = 'Pet Birthday '.random_int(1000, 9999); // unique across runs on the shared database

        // Already on the list (any capitals / spacing), or one typo away: nothing is queued.
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => '  wEDDING '])->assertOk()
            ->assertJsonPath('data.result', 'exists')->assertJsonPath('data.event_type', 'Wedding');
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => 'Weding'])->assertOk()
            ->assertJsonPath('data.result', 'similar')->assertJsonPath('data.event_type', 'Wedding');
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => 'Concerts'])->assertOk()->assertJsonPath('data.event_type', 'Concert');

        // A new one goes to the admins; asking twice doesn't queue it twice; another organizer joins the same row.
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => $name])->assertCreated()->assertJsonPath('data.result', 'pending');
        $this->actingAs($ownerA)->spaJson('POST', $url($a), ['name' => mb_strtoupper($name)])->assertOk()->assertJsonPath('data.result', 'already_suggested');
        $this->actingAs($ownerB)->spaJson('POST', $url($b), ['name' => strtolower($name)])->assertCreated();

        $row = collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/event-type-suggestions')->assertOk()->json('data'))
            ->first(fn ($r) => strcasecmp($r['name'], $name) === 0);
        $this->assertCount(2, $row['organizations']);
        $this->assertSame(1, DB::table('notifications')->where('type', 'admin.event_type')->where('recipient_user_id', $admin->id)->count());

        // Organizers can't decide; a rejection needs a reason.
        $this->actingAs($ownerA)->spaJson('POST', "/api/v1/admin/event-type-suggestions/{$row['id']}/approve")->assertForbidden();
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/event-type-suggestions/{$row['id']}/reject")->assertUnprocessable();

        // Approve with corrected spelling: a standard type, on both profiles, both told.
        $fixed = "{$name} party";
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/event-type-suggestions/{$row['id']}/approve", ['name' => $fixed])->assertOk();
        $this->assertTrue(DB::table('event_types')->whereNull('organization_id')->where('name', $fixed)->exists());
        $this->assertContains($fixed, $this->tags($a->id));
        $this->assertContains($fixed, $this->tags($b->id));
        $this->assertSame(1, DB::table('notifications')->where('type', 'event_type.decided')->where('recipient_user_id', $ownerB->id)->count());
        $this->actingAs($ownerA)->spaJson('GET', $url($a))->assertOk()->assertJsonPath('data.0.status', 'approved')->assertJsonPath('data.0.event_type', $fixed);
        // Now it's on the list for everyone.
        $this->actingAs($ownerB)->spaJson('POST', $url($b), ['name' => $fixed])->assertOk()->assertJsonPath('data.result', 'exists');

        // "It's really new" after a near match, then merged into the existing type.
        $this->actingAs($ownerB)->spaJson('POST', $url($b), ['name' => 'Debuts party', 'confirm_new' => true])->assertCreated();
        $merge = collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/event-type-suggestions')->json('data'))->firstWhere('name', 'Debuts party');
        $debut = DB::table('event_types')->whereNull('organization_id')->where('name', 'Debut')->value('id');
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/event-type-suggestions/{$merge['id']}/merge", ['event_type_id' => $debut])->assertOk();
        $this->assertContains('Debut', $this->tags($b->id));
        $this->assertSame('merged', DB::table('event_type_suggestions')->where('id', $merge['id'])->value('status'));
    }
}
