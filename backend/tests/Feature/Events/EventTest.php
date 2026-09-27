<?php

namespace Tests\Feature\Events;

use App\Models\Organization;
use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DefinesTestRoutes;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class EventTest extends TestCase
{
    use DefinesTestRoutes, InteractsWithOrganizations;

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function local(string $modifier): string
    {
        return now('Asia/Manila')->modify($modifier)->format('Y-m-d\TH:i');
    }

    private function eventPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Santos-Reyes Wedding',
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'venue_name' => 'Pearl Garden',
            'venue_address' => 'Lanang, Davao City',
            'overall_capacity' => 150,
            'sessions' => [['name' => 'Reception', 'starts_at' => $this->local('+30 days 17:00'), 'ends_at' => $this->local('+30 days 22:00')]],
        ], $overrides);
    }

    private function create(User $owner, Organization $org, array $overrides = [])
    {
        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", $this->eventPayload($overrides), $this->key());
    }

    /** An inquiry from $client won by $org; returns [inquiryId, quotationId]. */
    private function wonQuotation(User $client, Organization $org, User $owner, ?Organization $loser = null, ?User $loserOwner = null): array
    {
        $inquiry = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(),
            'guest_count' => 90,
            'location_text' => 'Lanang, Davao City',
        ])->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$inquiry}/submit",
            ['mode' => 'direct', 'organization_ids' => array_values(array_filter([$org->id, $loser?->id]))], $this->key())->assertOk();

        $items = ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 50000]], 'submit' => true];
        $quotation = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$inquiry}/quotations", $items, $this->key())->json('data.id');
        if ($loser) {
            $this->actingAs($loserOwner)->spaJson('POST', "/api/v1/organizations/{$loser->id}/inquiries/{$inquiry}/quotations", $items, $this->key())->assertCreated();
        }
        $this->actingAs($client)->spaJson('POST', "/api/v1/quotations/{$quotation}/accept", [], $this->key())->assertOk();

        return [$inquiry, $quotation];
    }

    public function test_owner_creates_an_event_with_its_schedule(): void
    {
        [$org, $owner] = $this->listedOrganizer();

        $event = $this->create($owner, $org)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.visibility', 'private')
            ->assertJsonPath('data.event_type', 'Wedding')
            ->assertJsonPath('data.sessions.0.name', 'Reception')
            ->assertJsonPath('data.sessions.0.starts_local', $this->local('+30 days 17:00'))
            ->json('data');

        // Stored in UTC, shown in the event timezone (+08:00).
        $this->assertStringEndsWith('+08:00', $event['starts_at']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'event.created', 'entity_id' => $event['id']]);

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events")->assertOk()->assertJsonPath('data.0.id', $event['id']);
    }

    public function test_event_validation(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$other, $otherOwner] = $this->listedOrganizer();
        $foreignType = $this->actingAs($otherOwner)->spaJson('POST', "/api/v1/organizations/{$other->id}/event-types", ['name' => 'Their Kind'])->json('data.id');

        $this->create($owner, $org, ['name' => '', 'visibility' => 'secret', 'latitude' => 7.1])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['name', 'visibility', 'longitude']]]]);
        $this->create($owner, $org, ['event_type_id' => $foreignType])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['event_type_id']]]]);
        $this->create($owner, $org, ['sessions' => [['starts_at' => $this->local('+5 days 18:00'), 'ends_at' => $this->local('+5 days 17:00')]]])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['ends_at']]]]);
        $this->create($owner, $org, ['sessions' => [['starts_at' => $this->local('-1 day'), 'ends_at' => $this->local('+1 day')]]])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['starts_at']]]]);

        $this->assertSame(0, DB::table('events')->where('organization_id', $org->id)->count());
    }

    public function test_lifecycle_and_terminal_states(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $bare = $this->create($owner, $org, ['sessions' => []])->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events";

        $this->actingAs($owner)->spaJson('POST', "{$base}/{$bare}/publish")->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['sessions']]]]);

        $id = $this->create($owner, $org)->json('data.id');
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$id}/publish")->assertOk()->assertJsonPath('data.status', 'published');
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$id}/publish")->assertConflict();
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$id}/complete")->assertConflict(); // not started yet

        // Once the first session has begun it can be run and completed.
        DB::table('event_sessions')->where('event_id', $id)->update(['starts_at' => now()->subHours(2), 'ends_at' => now()->subHour()]);
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$id}/start")->assertOk()->assertJsonPath('data.status', 'ongoing');
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$id}/complete")->assertOk()->assertJsonPath('data.status', 'completed');

        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$id}", ['name' => 'Renamed'])->assertConflict();
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$id}/cancel", ['reason' => 'Too late'])->assertConflict();

        // Cancelling needs a reason and cancels the schedule.
        $other = $this->create($owner, $org)->json('data.id');
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$other}/cancel", [])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$other}/cancel", ['reason' => 'Venue flooded'])->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.cancelled_reason', 'Venue flooded')
            ->assertJsonPath('data.sessions.0.status', 'cancelled');
    }

    public function test_sessions_can_be_managed_until_the_event_ends(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->create($owner, $org)->json('data');
        $base = "/api/v1/organizations/{$org->id}/events/{$event['id']}";

        $second = $this->actingAs($owner)->spaJson('POST', "{$base}/sessions", [
            'name' => 'After-party', 'starts_at' => $this->local('+30 days 22:30'), 'ends_at' => $this->local('+31 days 01:00'),
        ])->assertCreated()->assertJsonCount(2, 'data.sessions')->json('data.sessions.1.id');

        $this->actingAs($owner)->spaJson('PATCH', "{$base}/sessions/{$second}", [
            'name' => 'Late party', 'starts_at' => $this->local('+30 days 23:00'), 'ends_at' => $this->local('+31 days 02:00'),
        ])->assertOk()->assertJsonPath('data.sessions.1.name', 'Late party');

        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();
        $this->actingAs($owner)->spaJson('DELETE', "{$base}/sessions/{$second}")->assertOk()->assertJsonCount(1, 'data.sessions');
        $this->actingAs($owner)->spaJson('DELETE', "{$base}/sessions/{$event['sessions'][0]['id']}")->assertConflict(); // last one of a published event
    }

    public function test_accepted_quotation_converts_into_one_event_the_client_can_see(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        [$loser, $loserOwner] = $this->listedOrganizer();
        [$inquiry, $quotation] = $this->wonQuotation($client, $org, $owner, $loser, $loserOwner);
        $lostQuote = DB::table('quotations')->where('inquiry_id', $inquiry)->where('organization_id', $loser->id)->value('id');

        // The losing organizer can't convert.
        $this->actingAs($loserOwner)->spaJson('POST', "/api/v1/organizations/{$loser->id}/quotations/{$lostQuote}/event", $this->eventPayload(), $this->key())
            ->assertConflict();

        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/quotations/{$quotation}/event", $this->eventPayload(), $this->key())
            ->assertCreated()
            ->assertJsonPath('data.inquiry_id', $inquiry)
            ->assertJsonPath('data.accepted_quotation_id', $quotation)
            ->assertJsonPath('data.client.email', $client->email)
            ->json('data.id');

        $this->assertSame('converted_to_event', DB::table('inquiries')->where('id', $inquiry)->value('status'));
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/quotations/{$quotation}/event", $this->eventPayload(), $this->key())
            ->assertConflict();

        // The client sees their event and its schedule, with the organizer's contact.
        $this->actingAs($client)->spaJson('GET', '/api/v1/events')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $event);
        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}")->assertOk()
            ->assertJsonPath('data.organizer.slug', DB::table('organizations')->where('id', $org->id)->value('slug'))
            ->assertJsonPath('data.sessions.0.name', 'Reception');

        // Nobody else does.
        $this->actingAs(User::factory()->create())->spaJson('GET', "/api/v1/events/{$event}")->assertNotFound();
        $this->actingAs($loserOwner)->spaJson('GET', "/api/v1/organizations/{$loser->id}/events/{$event}")->assertNotFound();

        // A converted event is cancelled, not deleted.
        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/events/{$event}")->assertConflict();
    }

    public function test_independent_drafts_can_be_deleted(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->create($owner, $org)->json('data.id');

        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/events/{$id}")->assertOk();
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$id}")->assertNotFound();
        $this->assertNotNull(DB::table('events')->where('id', $id)->value('deleted_at'));
    }

    public function test_permissions_and_operational_state(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $this->addStaff($org, $staff = $this->businessUser());
        $id = $this->create($owner, $org)->json('data.id');

        // B8: staff without organization-wide event rights only see events they're assigned to.
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$id}")->assertNotFound();
        $this->assignToEvent($owner, $org, $id, $staff);
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$id}")->assertOk();
        $this->actingAs($staff)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/events/{$id}", ['name' => 'Hijack'])->assertForbidden();
        $this->create($staff, $org)->assertForbidden();

        DB::table('organizations')->where('id', $org->id)->update([
            'approval_status' => 'suspended', 'is_suspended' => true, 'suspended_reason' => 'x', 'suspended_at' => now(), 'suspended_by' => $this->adminUser()->id,
        ]);
        $this->create($owner, $org)->assertForbidden()->assertJsonPath('error.code', 'ORGANIZATION_NOT_OPERATIONAL');
    }

    public function test_database_refuses_linking_an_event_to_an_inquiry_directly(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->create($owner, $org)->json('data.id');
        $inquiry = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', ['guest_count' => 10])->json('data.id');

        $this->apiRoute('POST', 'link/{event}/{inquiry}', fn (string $event, string $inquiry) => ApiResponse::success(
            DB::table('events')->where('id', $event)->update(['inquiry_id' => $inquiry]),
        ), ['auth:sanctum']);

        $this->actingAs($owner)->spaJson('POST', "/api/v1/_test/link/{$id}/{$inquiry}")->assertForbidden();
        $this->assertNull(DB::table('events')->where('id', $id)->value('inquiry_id'));
    }
}
