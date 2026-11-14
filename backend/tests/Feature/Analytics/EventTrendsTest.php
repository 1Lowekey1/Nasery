<?php

namespace Tests\Feature\Analytics;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** NaSeRy-wide event trends: counts per month and event type, for organizers and admins only. */
class EventTrendsTest extends TestCase
{
    use InteractsWithOrganizations;

    public function test_organizers_and_admins_see_requests_and_events_per_event_type(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $wedding = DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id');
        $client = User::factory()->create(['account_type' => 'client']);

        $before = $this->actingAs($owner)->spaJson('GET', '/api/v1/event-trends?months=3')->assertOk()->json('data');
        $weddingRequests = fn (array $d) => array_sum(collect($d['types'])->firstWhere('name', 'Wedding')['requests'] ?? []);

        // One submitted wedding request and one draft (drafts don't count); one booked wedding event.
        foreach (['submitted', 'draft'] as $status) {
            DB::table('inquiries')->insert(['id' => (string) Str::uuid7(), 'client_user_id' => $client->id, 'event_type_id' => $wedding,
                'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        $day = now('Asia/Manila')->addDays(20)->format('Y-m-d');
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Trend Wedding', 'event_type_id' => $wedding, 'sessions' => [['starts_at' => "{$day}T10:00", 'ends_at' => "{$day}T18:00"]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $after = $this->actingAs($owner)->spaJson('GET', '/api/v1/event-trends?months=3')->assertOk()
            ->assertJsonCount(3, 'data.months')->json('data');
        $this->assertSame($weddingRequests($before) + 1, $weddingRequests($after));
        $this->assertGreaterThanOrEqual(1, array_sum(collect($after['types'])->firstWhere('name', 'Wedding')['events']));
        $this->assertSame(now('Asia/Manila')->format('Y-m'), end($after['months']));

        // Admins too; clients can't, and only 3/6/12 months are offered.
        $this->actingAs($this->adminUser())->spaJson('GET', '/api/v1/event-trends')->assertOk();
        $this->actingAs($client)->spaJson('GET', '/api/v1/event-trends')->assertForbidden();
        $this->actingAs($owner)->spaJson('GET', '/api/v1/event-trends?months=99')->assertUnprocessable();
    }

    public function test_an_organizer_sees_the_event_types_they_handle_most(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $type = fn (string $name) => DB::table('event_types')->whereNull('organization_id')->where('name', $name)->value('id');
        $day = now('Asia/Manila')->addDays(20)->format('Y-m-d');
        $event = fn (string $name, ?string $typeId) => $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => $name, 'event_type_id' => $typeId, 'sessions' => [['starts_at' => "{$day}T10:00", 'ends_at' => "{$day}T18:00"]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();
        $event('Wedding one', $type('Wedding'));
        $event('Wedding two', $type('Wedding'));
        $event('Debut', $type('Debut'));

        $data = $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/top-event-types?months=3")->assertOk()->json('data');
        $this->assertSame(3, $data['totals']['events']);
        $this->assertSame(['name' => 'Wedding', 'events' => 2, 'requests' => 0, 'share' => 67], $data['types'][0]);
        $this->assertSame('Debut', $data['types'][1]['name']);

        // Only this organization's own data: other organizers can't open it.
        [, $other] = $this->listedOrganizer('Someone Else');
        $this->actingAs($other)->spaJson('GET', "/api/v1/organizations/{$org->id}/top-event-types")->assertNotFound();
    }

    public function test_the_event_types_tab_breaks_down_the_organizer_and_nasery(): void
    {
        [$org, $owner] = $this->listedOrganizer('Breakdown Co', ['event_tags' => ['Wedding', 'Reunion']]);
        $wedding = DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id');
        $day = now('Asia/Manila')->addDays(20)->format('Y-m-d');
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Breakdown Wedding', 'event_type_id' => $wedding, 'sessions' => [['starts_at' => "{$day}T10:00", 'ends_at' => "{$day}T18:00"]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $data = $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/event-type-breakdown?range=3m")->assertOk()
            ->assertJsonCount(3, 'data.period.keys')->json('data');

        // Theirs: the wedding they booked this month, and Reunion listed because it's on their profile.
        $mine = collect($data['mine'])->keyBy('name');
        $this->assertSame(1, $mine['Wedding']['events']);
        $this->assertSame(1, array_slice($mine['Wedding']['events_by_month'], -1)[0]);
        $this->assertTrue($mine['Reunion']['on_profile']);
        $this->assertSame(0, $mine['Reunion']['events']);
        $this->assertArrayHasKey('win_rate', $mine['Wedding']);

        // NaSeRy: every standard type, with how many listed organizers offer it.
        $market = collect($data['market'])->keyBy('name');
        $this->assertGreaterThanOrEqual(1, $market['Wedding']['booked']);
        $this->assertGreaterThanOrEqual(1, $market['Reunion']['organizers']);
        $this->assertCount(3, $market['Wedding']['requests_by_month']);

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/event-type-breakdown?range=24m")->assertUnprocessable();
    }
}
