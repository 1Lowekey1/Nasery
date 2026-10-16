<?php

namespace Tests\Feature\Inquiries;

use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DefinesTestRoutes;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class InquiryTest extends TestCase
{
    use DefinesTestRoutes, InteractsWithOrganizations;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create(); // client account
    }

    private function eventTypeId(string $name = 'Wedding'): string
    {
        return DB::table('event_types')->whereNull('organization_id')->where('name', $name)->value('id');
    }

    private function draft(array $overrides = []): string
    {
        return $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', array_replace([
            'event_type_id' => $this->eventTypeId(),
            'requested_date' => now()->addMonths(3)->toDateString(),
            'guest_count' => 150,
            'budget_min' => 150000,
            'budget_max' => 250000,
            'location_text' => 'Matina, Davao City',
            'latitude' => 7.0707,
            'longitude' => 125.6087,
            'additional_requirements' => 'Garden setup, rain plan needed.',
            'services' => [['custom_service_name' => 'Wedding coordination', 'notes' => 'Full day']],
        ], $overrides))->assertCreated()->assertJsonPath('data.status', 'draft')->json('data.id');
    }

    private function submit(string $id, array $payload)
    {
        return $this->actingAs($this->client)->spaJson('POST', "/api/v1/inquiries/{$id}/submit", $payload);
    }

    public function test_only_verified_clients_create_inquiries(): void
    {
        $this->actingAs($this->businessUser())->spaJson('POST', '/api/v1/inquiries', [])->assertForbidden()
            ->assertJsonPath('error.code', 'ACCOUNT_TYPE_NOT_ALLOWED');
        $this->actingAs(User::factory()->unverified()->create())->spaJson('POST', '/api/v1/inquiries', [])->assertForbidden();
    }

    public function test_draft_validation(): void
    {
        $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', [
            'requested_date' => now()->subDay()->toDateString(),
            'budget_min' => 5000, 'budget_max' => 1000,
            'latitude' => 7.1,
            'guest_count' => 0,
        ])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['requested_date', 'budget_max', 'longitude', 'guest_count']]]]);

        // Dates up to December 31, three years from now.
        $last = now('Asia/Manila')->addYears(3)->endOfYear();
        $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', ['requested_date' => $last->toDateString()])->assertCreated();
        $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', ['requested_date' => $last->copy()->addDay()->toDateString()])->assertUnprocessable()
            ->assertJsonPath('error.details.fields.requested_date.0', "Choose an event date on or before December 31, {$last->year}.");

        // One-sided budgets ("Under ₱50,000", "Over ₱800,000") are fine; a draft keeps its other side.
        $under = $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', ['budget_min' => null, 'budget_max' => 50000])->assertCreated()->json('data.id');
        $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', ['budget_min' => 800000, 'budget_max' => null])->assertCreated();
        $this->actingAs($this->client)->spaJson('PATCH', "/api/v1/inquiries/{$under}", ['budget_min' => 60000])->assertUnprocessable()
            ->assertJsonPath('error.details.fields.budget_max.0', 'The maximum budget must be at least the minimum budget.');

        // Organizer-owned event types aren't offered to clients.
        [$org, $owner] = $this->listedOrganizer();
        $custom = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/event-types", ['name' => 'Private Kind'])->json('data.id');
        $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', ['event_type_id' => $custom])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['event_type_id']]]]);
    }

    public function test_direct_inquiry_reaches_only_the_chosen_listed_organizers(): void
    {
        [$chosen] = $this->listedOrganizer('Chosen Co');
        [$other] = $this->listedOrganizer('Other Co');
        $id = $this->draft();

        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$chosen->id]])
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.via', 'direct')
            ->assertJsonPath('data.invitations.0.organization_id', $chosen->id)
            ->assertJsonPath('data.invitations.0.status', 'pending')
            ->assertJsonCount(1, 'data.invitations');

        $this->assertDatabaseMissing('inquiry_organizations', ['inquiry_id' => $id, 'organization_id' => $other->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'inquiry.submitted', 'entity_id' => $id]);
    }

    public function test_unlisted_organizers_cannot_be_invited(): void
    {
        $pending = $this->onboard($this->businessUser()); // not approved
        $id = $this->draft();

        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$pending->id]])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['organization_ids']]]]);
        $this->assertSame('draft', DB::table('inquiries')->where('id', $id)->value('status'));
    }

    public function test_open_posting_invites_matching_organizers_within_range(): void
    {
        [$near] = $this->listedOrganizer('Near Match', ['latitude' => 7.0731, 'longitude' => 125.6128], ['Wedding Coordination']);
        [$wrongService] = $this->listedOrganizer('Near Caterer', ['latitude' => 7.0750, 'longitude' => 125.6100], ['Catering']);
        [$far] = $this->listedOrganizer('Far Match', ['latitude' => 14.5995, 'longitude' => 120.9842], ['Wedding Coordination']);
        $id = $this->draft();

        $preview = $this->actingAs($this->client)->spaJson('GET', "/api/v1/inquiries/{$id}/matches?match_radius_km=30")->assertOk();
        $this->assertContains($near->id, array_column($preview->json('data'), 'organization_id'));

        $invited = collect($this->submit($id, ['mode' => 'open', 'match_radius_km' => 30])
            ->assertOk()->assertJsonPath('data.via', 'open_posting')->assertJsonPath('data.match_radius_km', 30)
            ->json('data.invitations'))->pluck('organization_id');

        $this->assertContains($near->id, $invited);
        $this->assertNotContains($wrongService->id, $invited);
        $this->assertNotContains($far->id, $invited);
    }

    public function test_open_posting_with_no_matches_is_refused_and_stays_a_draft(): void
    {
        $id = $this->draft(['services' => [['custom_service_name' => 'Zz'.Str::random(8).' Hovercraft rides']]]);

        $this->submit($id, ['mode' => 'open', 'match_radius_km' => 5])
            ->assertUnprocessable()->assertJsonPath('error.code', 'NO_MATCHING_ORGANIZERS');
        $this->assertSame('draft', DB::table('inquiries')->where('id', $id)->value('status'));
    }

    public function test_submission_requires_a_complete_inquiry_and_happens_once(): void
    {
        [$org] = $this->listedOrganizer();
        $incomplete = $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', ['location_text' => 'Davao'])->json('data.id');

        $this->submit($incomplete, ['mode' => 'direct', 'organization_ids' => [$org->id]])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['event_type_id', 'requested_date', 'guest_count']]]]);

        $id = $this->draft();
        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$org->id]])->assertOk();
        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$org->id]])
            ->assertConflict()->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');
    }

    public function test_drafts_are_editable_and_sent_inquiries_are_not(): void
    {
        [$org] = $this->listedOrganizer();
        $id = $this->draft();

        $this->actingAs($this->client)->spaJson('PATCH', "/api/v1/inquiries/{$id}", [
            'guest_count' => 200,
            'services' => [['custom_service_name' => 'Lights and Sound'], ['custom_service_name' => 'Catering']],
        ])->assertOk()->assertJsonPath('data.guest_count', 200)->assertJsonCount(2, 'data.services');

        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$org->id]])->assertOk();
        $this->actingAs($this->client)->spaJson('PATCH', "/api/v1/inquiries/{$id}", ['guest_count' => 10])->assertConflict();
        $this->actingAs($this->client)->spaJson('DELETE', "/api/v1/inquiries/{$id}")->assertConflict();
    }

    public function test_clients_are_isolated_from_each_other(): void
    {
        $id = $this->draft();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->spaJson('GET', "/api/v1/inquiries/{$id}")->assertNotFound();
        $this->actingAs($intruder)->spaJson('POST', "/api/v1/inquiries/{$id}/cancel")->assertNotFound();
        $this->actingAs($intruder)->spaJson('GET', '/api/v1/inquiries')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_organizer_sees_and_declines_invitations_only(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$otherOrg, $otherOwner] = $this->listedOrganizer();
        $id = $this->draft();
        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$org->id]])->assertOk();

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/inquiries")
            ->assertOk()->assertJsonPath('data.0.inquiry_id', $id)->assertJsonPath('data.0.invitation_status', 'pending');

        // Viewing marks it viewed; the client stays anonymous.
        $detail = $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/inquiries/{$id}")
            ->assertOk()->assertJsonPath('data.invitation_status', 'viewed')
            ->assertJsonPath('data.additional_requirements', 'Garden setup, rain plan needed.')
            ->assertJsonPath('data.services.0.name', 'Wedding coordination')
            ->json('data');
        $this->assertSame($this->client->first_name.' '.mb_substr($this->client->last_name, 0, 1).'.', $detail['client']);
        $this->assertStringNotContainsString($this->client->email, json_encode($detail));

        // A non-invited organizer can't even see it.
        $this->actingAs($otherOwner)->spaJson('GET', "/api/v1/organizations/{$otherOrg->id}/inquiries/{$id}")->assertNotFound();

        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$id}/decline", ['reason' => 'Fully booked that date'])
            ->assertOk()->assertJsonPath('data.invitation_status', 'declined');
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$id}/decline", ['reason' => 'again'])
            ->assertConflict();

        $this->actingAs($this->client)->spaJson('GET', "/api/v1/inquiries/{$id}")
            ->assertJsonPath('data.invitations.0.status', 'declined')
            ->assertJsonPath('data.invitations.0.decline_reason', 'Fully booked that date');
    }

    public function test_staff_without_permission_and_suspended_orgs_cannot_respond(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $this->addStaff($org, $staff = $this->businessUser());
        $id = $this->draft();
        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$org->id]])->assertOk();

        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/inquiries")->assertForbidden();

        DB::table('organizations')->where('id', $org->id)->update([
            'approval_status' => 'suspended', 'is_suspended' => true, 'suspended_reason' => 'x', 'suspended_at' => now(), 'suspended_by' => $this->adminUser()->id,
        ]);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$id}/decline", ['reason' => 'No longer active'])
            ->assertForbidden()->assertJsonPath('error.code', 'ORGANIZATION_NOT_OPERATIONAL');
    }

    public function test_cancelled_inquiries_close_for_organizers(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->draft();
        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$org->id]])->assertOk();

        $this->actingAs($this->client)->spaJson('POST', "/api/v1/inquiries/{$id}/cancel", ['reason' => 'Event postponed'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/inquiries/{$id}")
            ->assertOk()->assertJsonPath('data.is_open', false)->assertJsonPath('data.invitation_status', 'pending');
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$id}/decline", ['reason' => 'x x x'])
            ->assertConflict();
    }

    public function test_client_can_invite_more_organizers_while_open(): void
    {
        [$first] = $this->listedOrganizer();
        [$second] = $this->listedOrganizer();
        $id = $this->draft();
        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$first->id]])->assertOk();

        $this->actingAs($this->client)->spaJson('POST', "/api/v1/inquiries/{$id}/invite", ['organization_ids' => [$first->id, $second->id]])
            ->assertOk()->assertJsonCount(2, 'data.invitations');
    }

    public function test_database_refuses_self_invitation_by_an_organizer(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->draft();

        $this->apiRoute('POST', 'self-invite/{inquiry}/{org}', fn (string $inquiry, string $org) => ApiResponse::success(
            DB::table('inquiry_organizations')->insert(['id' => (string) Str::uuid(), 'inquiry_id' => $inquiry, 'organization_id' => $org]),
        ), ['auth:sanctum']);

        $this->actingAs($owner)->spaJson('POST', "/api/v1/_test/self-invite/{$id}/{$org->id}")->assertForbidden();
        $this->assertDatabaseMissing('inquiry_organizations', ['inquiry_id' => $id]);
    }

    public function test_stale_open_inquiries_auto_close(): void
    {
        [$org] = $this->listedOrganizer();
        $id = $this->draft();
        $this->submit($id, ['mode' => 'direct', 'organization_ids' => [$org->id]])->assertOk();
        DB::table('inquiry_organizations')->where('inquiry_id', $id)->update(['created_at' => now()->subDays(31)]);

        $this->artisan('nasery:inquiries:close-stale')->assertSuccessful();

        $this->assertSame('cancelled', DB::table('inquiries')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'inquiry.auto_closed', 'entity_id' => $id]);
    }
}
