<?php

namespace Tests\Feature\Quotations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DefinesTestRoutes;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** 0051: a client changes a sent request; organizers are told and may revise. */
class RequestRevisionTest extends TestCase
{
    use DefinesTestRoutes, InteractsWithOrganizations;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create();
    }

    private function inquiry(Organization ...$orgs): string
    {
        $id = $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(3)->toDateString(),
            'guest_count' => 120,
            'budget_min' => 100000,
            'budget_max' => 150000,
            'location_text' => 'Lanang, Davao City',
            'services' => [['custom_service_name' => 'Wedding coordination']],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->client)->spaJson('POST', "/api/v1/inquiries/{$id}/submit", [
            'mode' => 'direct', 'organization_ids' => array_map(fn (Organization $o) => $o->id, $orgs),
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return $id;
    }

    private function quote(User $owner, Organization $org, string $inquiryId): string
    {
        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$inquiryId}/quotations", [
            'package_name' => 'Garden Wedding Package',
            'items' => [['description' => 'Coordination team (full day)', 'quantity' => 1, 'unit_price' => 45000]],
            'submit' => true,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
    }

    public function test_client_changes_a_sent_request_and_quoted_organizers_can_revise(): void
    {
        [$quoted, $quotedOwner] = $this->listedOrganizer();
        [$waiting, $waitingOwner] = $this->listedOrganizer();
        [$declined, $declinedOwner] = $this->listedOrganizer();
        $id = $this->inquiry($quoted, $waiting, $declined);
        $q = $this->quote($quotedOwner, $quoted, $id);
        $this->actingAs($declinedOwner)->spaJson('POST', "/api/v1/organizations/{$declined->id}/inquiries/{$id}/decline", ['reason' => 'Fully booked that day'])->assertOk();
        $this->travel(2)->seconds();

        $this->actingAs($this->client)->spaJson('PATCH', "/api/v1/inquiries/{$id}", [
            'guest_count' => 180,
            'budget_max' => 200000,
            'additional_requirements' => 'Now an outdoor ceremony.',
            'services' => [['custom_service_name' => 'Wedding coordination'], ['custom_service_name' => 'Lights and sound']],
            // Not revisable after sending: ignored.
            'location_text' => 'Somewhere else',
        ])->assertOk()
            ->assertJsonPath('data.guest_count', 180)
            ->assertJsonPath('data.location_text', 'Lanang, Davao City')
            ->assertJsonPath('data.revisions_left', 4)
            ->assertJsonCount(2, 'data.services');

        // Told: the organizer who quoted and the one still deciding, not the one who declined.
        $told = fn (User $u) => DB::table('notifications')->where('recipient_user_id', $u->id)->where('type', 'inquiry.revised')->count();
        $this->assertSame(1, $told($quotedOwner));
        $this->assertSame(1, $told($waitingOwner));
        $this->assertSame(0, $told($declinedOwner));
        $this->assertStringContainsString('the guest count, the budget, the notes and the services',
            DB::table('notifications')->where('recipient_user_id', $quotedOwner->id)->where('type', 'inquiry.revised')->value('body'));

        // Both sides see the offer predates the change.
        $this->actingAs($this->client)->spaJson('GET', "/api/v1/inquiries/{$id}/quotations")->assertOk()->assertJsonPath('data.0.before_changes', true);
        $this->actingAs($quotedOwner)->spaJson('GET', "/api/v1/organizations/{$quoted->id}/inquiries/{$id}")->assertOk()
            ->assertJsonPath('data.quotation.before_changes', true)
            ->assertJsonPath('data.guest_count', 180);

        // The organizer revises: the new offer is current again.
        $this->travel(2)->seconds();
        $revised = $this->actingAs($quotedOwner)->spaJson('POST', "/api/v1/organizations/{$quoted->id}/quotations/{$q}/revise", [
            'package_name' => 'Garden Wedding Package (180 guests)',
            'items' => [['description' => 'Coordination team (full day)', 'quantity' => 1, 'unit_price' => 60000]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $this->actingAs($this->client)->spaJson('GET', "/api/v1/inquiries/{$id}/quotations")->assertOk()
            ->assertJsonPath('data.0.id', $revised)
            ->assertJsonPath('data.0.before_changes', false);
    }

    public function test_revision_rules(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);
        $patch = fn (array $data) => $this->actingAs($this->client)->spaJson('PATCH', "/api/v1/inquiries/{$id}", $data);

        $patch(['guest_count' => 120])->assertUnprocessable()->assertJsonPath('error.details.fields.request.0', 'Nothing was changed.');
        $patch(['guest_count' => null])->assertUnprocessable()->assertJsonPath('error.details.fields.guest_count.0', 'Add the expected number of guests.');
        $patch(['budget_min' => 300000])->assertUnprocessable()->assertJsonPath('error.details.fields.budget_max.0', 'The maximum budget must be at least the minimum budget.');

        // Capped so organizers aren't flooded.
        foreach (range(1, 5) as $n) {
            $patch(['guest_count' => 120 + $n])->assertOk();
        }
        $patch(['guest_count' => 200])->assertConflict();
        $this->assertSame(5, DB::table('inquiries')->where('id', $id)->value('revision_count'));

        // Other clients can't touch it; a cancelled request can't change.
        $this->actingAs(User::factory()->create())->spaJson('PATCH', "/api/v1/inquiries/{$id}", ['guest_count' => 10])->assertNotFound();
        $other = $this->inquiry($org);
        $this->actingAs($this->client)->spaJson('POST', "/api/v1/inquiries/{$other}/cancel", ['reason' => 'Postponed'])->assertOk();
        $this->actingAs($this->client)->spaJson('PATCH', "/api/v1/inquiries/{$other}", ['guest_count' => 90])->assertConflict();
    }
}
