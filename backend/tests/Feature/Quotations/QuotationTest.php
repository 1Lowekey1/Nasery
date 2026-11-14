<?php

namespace Tests\Feature\Quotations;

use App\Models\Organization;
use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DefinesTestRoutes;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use DefinesTestRoutes, InteractsWithOrganizations;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = User::factory()->create();
    }

    /** A sent (direct) inquiry to the given organizations. */
    private function inquiry(Organization ...$orgs): string
    {
        $id = $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(3)->toDateString(),
            'guest_count' => 120,
            'location_text' => 'Lanang, Davao City',
            'services' => [['custom_service_name' => 'Wedding coordination']],
        ])->assertCreated()->json('data.id');

        $this->actingAs($this->client)->spaJson('POST', "/api/v1/inquiries/{$id}/submit", [
            'mode' => 'direct', 'organization_ids' => array_map(fn (Organization $o) => $o->id, $orgs),
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();

        return $id;
    }

    private function content(array $overrides = []): array
    {
        return array_replace([
            'package_name' => 'Garden Wedding Package',
            'proposal' => 'Full coordination from planning to the last dance.',
            'terms' => '50% down payment, balance a week before the event.',
            'items' => [
                ['description' => 'Coordination team (full day)', 'quantity' => 1, 'unit_price' => 45000],
                ['description' => 'Catering per guest', 'quantity' => 120, 'unit_price' => 650.50],
            ],
        ], $overrides);
    }

    private function quote(User $owner, Organization $org, string $inquiryId, array $overrides = [], bool $submit = true): TestResponse
    {
        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$inquiryId}/quotations",
            [...$this->content($overrides), 'submit' => $submit], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function orgPath(Organization $org, string $quotationId, string $suffix = ''): string
    {
        return "/api/v1/organizations/{$org->id}/quotations/{$quotationId}{$suffix}";
    }

    public function test_draft_then_send_with_a_server_computed_total(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);

        $draft = $this->quote($owner, $org, $id, submit: false)->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total_amount', '123060.00') // 45,000 + 120 × 650.50
            ->assertJsonPath('data.items.1.subtotal', '78060.00')
            ->json('data.id');

        // Drafts are invisible to the client.
        $this->actingAs($this->client)->spaJson('GET', "/api/v1/inquiries/{$id}/quotations")->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($this->client)->spaJson('POST', "/api/v1/quotations/{$draft}/accept", [], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();

        $this->actingAs($owner)->spaJson('PATCH', $this->orgPath($org, $draft), $this->content(['items' => [
            ['description' => 'All-in package', 'quantity' => 3, 'unit_price' => 333.33],
            ['description' => 'Rounding check', 'quantity' => 1.5, 'unit_price' => 0.01],
        ]]))->assertOk()->assertJsonPath('data.total_amount', '1000.01'); // 999.99 + 0.015 → 0.02

        $this->actingAs($owner)->spaJson('POST', $this->orgPath($org, $draft, '/submit'), [], ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.status', 'submitted');
        $this->actingAs($owner)->spaJson('PATCH', $this->orgPath($org, $draft), $this->content())->assertConflict();

        $this->assertSame('receiving_bids', DB::table('inquiries')->where('id', $id)->value('status'));
        $this->assertSame('quoted', DB::table('inquiry_organizations')->where('inquiry_id', $id)->value('status'));
        $this->assertDatabaseHas('quotation_status_history', ['quotation_id' => $draft, 'from_status' => 'draft', 'to_status' => 'submitted', 'actor_user_id' => $owner->id]);

        $this->actingAs($this->client)->spaJson('GET', "/api/v1/inquiries/{$id}/quotations")->assertOk()
            ->assertJsonPath('data.0.id', $draft)
            ->assertJsonPath('data.0.organizer.id', $org->id)
            ->assertJsonPath('data.0.items.0.description', 'All-in package')
            ->assertJsonPath('data.0.package_name', 'Garden Wedding Package');
    }

    public function test_quotation_validation(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$otherOrg, $otherOwner] = $this->listedOrganizer();
        $foreignService = $this->actingAs($otherOwner)->spaJson('POST', "/api/v1/organizations/{$otherOrg->id}/services", ['custom_name' => 'Lights'])->json('data.id');
        $id = $this->inquiry($org);

        $this->quote($owner, $org, $id, ['items' => []])->assertUnprocessable()
            ->assertJsonPath('error.details.fields.items.0', 'Add at least one line to the cost breakdown.');
        $this->quote($owner, $org, $id, ['items' => [['description' => 'x', 'quantity' => 0, 'unit_price' => -1]]])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['items.0.description', 'items.0.quantity', 'items.0.unit_price']]]]);
        $this->quote($owner, $org, $id, ['items' => [['description' => 'Lights', 'quantity' => 1, 'unit_price' => 10, 'organization_service_id' => $foreignService]]])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['items']]]]);

        $this->assertDatabaseMissing('quotations', ['inquiry_id' => $id]);
    }

    public function test_only_invited_organizers_quote_once_and_not_after_declining(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$outsider, $outsiderOwner] = $this->listedOrganizer();
        [$decliner, $declinerOwner] = $this->listedOrganizer();
        $id = $this->inquiry($org, $decliner);

        $this->quote($outsiderOwner, $outsider, $id)->assertNotFound();

        $this->quote($owner, $org, $id)->assertCreated();
        $this->quote($owner, $org, $id)->assertConflict();

        $this->actingAs($declinerOwner)->spaJson('POST', "/api/v1/organizations/{$decliner->id}/inquiries/{$id}/decline", ['reason' => 'Fully booked'])->assertOk();
        $this->quote($declinerOwner, $decliner, $id)->assertConflict();
    }

    public function test_vat_is_added_at_the_admins_rate_and_cannot_be_typed(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);
        $typedVat = ['items' => [
            ['description' => 'Package', 'quantity' => 1, 'unit_price' => 10000],
            ['description' => 'VAT (50%)', 'quantity' => 1, 'unit_price' => 5000],
        ]];

        // Not VAT-registered: a typed VAT line is dropped.
        $first = $this->quote($owner, $org, $id, $typedVat)->assertCreated()->assertJsonPath('data.total_amount', '10000.00')->assertJsonCount(1, 'data.items')->json('data.id');

        // VAT-registered: NaSeRy's rate (admin setting) on the other lines, whatever was typed.
        DB::table('organization_profiles')->where('organization_id', $org->id)->update(['estimate_tax_percent' => 1]);
        app(\App\Services\Admin\PlatformSettings::class);
        DB::table('platform_settings')->updateOrInsert(['key' => 'vat_percent'], ['value' => json_encode(12)]);
        $this->actingAs($owner)->spaJson('POST', $this->orgPath($org, $first, '/revise'), $this->content($typedVat), ['Idempotency-Key' => (string) Str::uuid()])
            ->assertCreated()->assertJsonPath('data.total_amount', '11200.00')
            ->assertJsonPath('data.items.1.description', 'VAT (12%)')->assertJsonPath('data.items.1.subtotal', '1200.00');
    }

    public function test_revising_replaces_the_offer_and_keeps_the_earlier_version(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);
        $first = $this->quote($owner, $org, $id)->json('data.id');

        $revised = $this->actingAs($owner)->spaJson('POST', $this->orgPath($org, $first, '/revise'), $this->content([
            'items' => [['description' => 'Discounted package', 'quantity' => 1, 'unit_price' => 99000]],
        ]), ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('data.status', 'submitted')->json('data.id');

        $this->assertNotSame($first, $revised);
        $this->assertSame('withdrawn', DB::table('quotations')->where('id', $first)->value('status'));
        $this->assertSame('quoted', DB::table('inquiry_organizations')->where('inquiry_id', $id)->value('status'));

        $this->actingAs($this->client)->spaJson('GET', "/api/v1/inquiries/{$id}/quotations")->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $revised)
            ->assertJsonPath('data.0.total_amount', '99000.00')
            ->assertJsonPath('data.0.earlier_versions', 1);

        // The replaced version can't be revised or accepted.
        $this->actingAs($owner)->spaJson('POST', $this->orgPath($org, $first, '/revise'), $this->content(), ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
        $this->actingAs($this->client)->spaJson('POST', "/api/v1/quotations/{$first}/accept", [], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
    }

    public function test_withdrawing_reopens_the_slot(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);
        $q = $this->quote($owner, $org, $id)->json('data.id');

        $this->actingAs($owner)->spaJson('POST', $this->orgPath($org, $q, '/withdraw'), ['reason' => 'Date conflict came up'])
            ->assertOk()->assertJsonPath('data.status', 'withdrawn');

        $this->assertSame('withdrawn', DB::table('inquiry_organizations')->where('inquiry_id', $id)->value('status'));
        $this->assertSame('submitted', DB::table('inquiries')->where('id', $id)->value('status'));
        $this->assertDatabaseHas('quotation_status_history', ['quotation_id' => $q, 'to_status' => 'withdrawn', 'reason' => 'Date conflict came up']);

        $this->quote($owner, $org, $id)->assertCreated();
    }

    public function test_client_accepts_one_offer_and_competitors_are_rejected(): void
    {
        [$winner, $winnerOwner] = $this->listedOrganizer();
        [$loser, $loserOwner] = $this->listedOrganizer();
        [$drafter, $drafterOwner] = $this->listedOrganizer();
        $id = $this->inquiry($winner, $loser, $drafter);
        $win = $this->quote($winnerOwner, $winner, $id)->json('data.id');
        $lose = $this->quote($loserOwner, $loser, $id)->json('data.id');
        $draft = $this->quote($drafterOwner, $drafter, $id, submit: false)->json('data.id');

        $key = (string) Str::uuid();
        $this->actingAs($this->client)->spaJson('POST', "/api/v1/quotations/{$win}/accept", [], ['Idempotency-Key' => $key])->assertOk();
        // Same key replays; a new attempt is refused.
        $this->actingAs($this->client)->spaJson('POST', "/api/v1/quotations/{$win}/accept", [], ['Idempotency-Key' => $key])->assertOk();
        $this->actingAs($this->client)->spaJson('POST', "/api/v1/quotations/{$lose}/accept", [], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();

        $status = fn (string $q) => DB::table('quotations')->where('id', $q)->value('status');
        $this->assertSame(['accepted', 'rejected', 'cancelled'], [$status($win), $status($lose), $status($draft)]);
        $this->assertSame('accepted', DB::table('inquiries')->where('id', $id)->value('status'));
        $this->assertSame('accepted', DB::table('inquiry_organizations')->where('inquiry_id', $id)->where('organization_id', $winner->id)->value('status'));
        $this->assertSame('rejected', DB::table('inquiry_organizations')->where('inquiry_id', $id)->where('organization_id', $loser->id)->value('status'));

        // Only the winning organizer now sees the client's contact details.
        $this->actingAs($winnerOwner)->spaJson('GET', $this->orgPath($winner, $win))->assertOk()
            ->assertJsonPath('data.client.email', $this->client->email)
            ->assertJsonPath('data.status', 'accepted');
        $this->actingAs($loserOwner)->spaJson('GET', $this->orgPath($loser, $lose))->assertOk()
            ->assertJsonPath('data.client.email', null)
            ->assertJsonPath('data.status', 'rejected');
        $this->actingAs($drafterOwner)->spaJson('POST', $this->orgPath($drafter, $draft, '/submit'), [], ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
    }

    public function test_client_declines_an_offer_with_a_reason(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);
        $q = $this->quote($owner, $org, $id)->json('data.id');

        $this->actingAs($this->client)->spaJson('POST', "/api/v1/quotations/{$q}/decline", ['reason' => ''])->assertUnprocessable();
        $this->actingAs($this->client)->spaJson('POST', "/api/v1/quotations/{$q}/decline", ['reason' => 'Over our budget'])->assertOk();

        $this->assertSame('rejected', DB::table('quotations')->where('id', $q)->value('status'));
        $this->assertSame($this->client->id, DB::table('quotations')->where('id', $q)->value('decided_by'));
        $this->assertSame('rejected', DB::table('inquiry_organizations')->where('inquiry_id', $id)->value('status'));
        $this->assertSame('submitted', DB::table('inquiries')->where('id', $id)->value('status'));
        $this->quote($owner, $org, $id)->assertConflict(); // decided: no re-quote
    }

    public function test_cancelling_the_inquiry_cancels_open_quotations(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);
        $q = $this->quote($owner, $org, $id)->json('data.id');

        $this->actingAs($this->client)->spaJson('POST', "/api/v1/inquiries/{$id}/cancel", ['reason' => 'Postponed'])->assertOk();

        $this->assertSame('cancelled', DB::table('quotations')->where('id', $q)->value('status'));
        $this->actingAs($owner)->spaJson('POST', $this->orgPath($org, $q, '/revise'), $this->content(), ['Idempotency-Key' => (string) Str::uuid()])->assertConflict();
    }

    public function test_other_clients_and_non_permitted_staff_are_kept_out(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $this->addStaff($org, $staff = $this->businessUser());
        $id = $this->inquiry($org);
        $q = $this->quote($owner, $org, $id)->json('data.id');

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->spaJson('GET', "/api/v1/inquiries/{$id}/quotations")->assertNotFound();
        $this->actingAs($stranger)->spaJson('POST', "/api/v1/quotations/{$q}/accept", [], ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();

        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/quotations")->assertForbidden();

        // A quotation id under another organization's URL is not found.
        [$otherOrg, $otherOwner] = $this->listedOrganizer();
        $this->actingAs($otherOwner)->spaJson('GET', $this->orgPath($otherOrg, $q))->assertNotFound();
    }

    public function test_suspended_organizers_cannot_send(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);
        DB::table('organizations')->where('id', $org->id)->update([
            'approval_status' => 'suspended', 'is_suspended' => true, 'suspended_reason' => 'x', 'suspended_at' => now(), 'suspended_by' => $this->adminUser()->id,
        ]);

        $this->quote($owner, $org, $id)->assertForbidden()->assertJsonPath('error.code', 'ORGANIZATION_NOT_OPERATIONAL');
    }

    public function test_database_refuses_direct_status_writes_by_the_organizer(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);
        $q = $this->quote($owner, $org, $id)->json('data.id');

        $this->apiRoute('POST', 'self-accept/{quotation}', fn (string $quotation) => ApiResponse::success(
            DB::table('quotations')->where('id', $quotation)->update(['status' => 'accepted']),
        ), ['auth:sanctum']);

        $this->actingAs($owner)->spaJson('POST', "/api/v1/_test/self-accept/{$q}")->assertForbidden();
        $this->assertSame('submitted', DB::table('quotations')->where('id', $q)->value('status'));
    }

    public function test_auto_close_counts_recent_quotations_as_activity(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $id = $this->inquiry($org);
        $q = $this->quote($owner, $org, $id)->json('data.id');
        DB::table('inquiry_organizations')->where('inquiry_id', $id)->update(['created_at' => now()->subDays(40)]);

        $this->artisan('nasery:inquiries:close-stale')->assertSuccessful();
        $this->assertSame('receiving_bids', DB::table('inquiries')->where('id', $id)->value('status'));

        DB::table('quotations')->where('id', $q)->update(['submitted_at' => now()->subDays(31)]);
        $this->artisan('nasery:inquiries:close-stale')->assertSuccessful();

        $this->assertSame('cancelled', DB::table('inquiries')->where('id', $id)->value('status'));
        $this->assertSame('cancelled', DB::table('quotations')->where('id', $q)->value('status'));
    }
}
