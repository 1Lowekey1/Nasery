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
}
