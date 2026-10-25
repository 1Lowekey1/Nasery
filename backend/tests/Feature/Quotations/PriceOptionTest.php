<?php

namespace Tests\Feature\Quotations;

use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DefinesTestRoutes;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class PriceOptionTest extends TestCase
{
    use DefinesTestRoutes, InteractsWithOrganizations;

    public function test_owner_manages_a_price_list_grouped_by_service(): void
    {
        [$org, $owner] = $this->listedOrganizer('Priced Co', [], ['Catering']);
        $catering = DB::table('organization_services')->where('organization_id', $org->id)->value('id');
        $base = "/api/v1/organizations/{$org->id}/price-options";

        $a = $this->actingAs($owner)->spaJson('POST', $base, [
            'organization_service_id' => $catering, 'name' => 'Partner A buffet', 'pricing_unit' => 'per_guest', 'price' => 450, 'min_guests' => 50,
        ])->assertCreated()->assertJsonPath('data.price', '450.00')->assertJsonPath('data.pricing_unit', 'per_guest')->json('data.id');
        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Full-day coordination', 'pricing_unit' => 'flat', 'price' => 45000])->assertCreated();

        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'x', 'pricing_unit' => 'per_week', 'price' => -1])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['name', 'pricing_unit', 'price']]]]);

        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$a}", ['price' => 480.5])->assertOk()->assertJsonPath('data.price', '480.50');
        $this->actingAs($owner)->spaJson('GET', $base)->assertOk()->assertJsonCount(2, 'data');
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.price_option_added', 'entity_id' => $a]);
    }

    public function test_hourly_daily_and_per_item_options_with_quantity_limits(): void
    {
        [$org, $owner] = $this->listedOrganizer('Unit Co');
        $base = "/api/v1/organizations/{$org->id}/price-options";

        // 0048: per item with a label and a 2–8 range; per hour with a minimum only.
        $this->actingAs($owner)->spaJson('POST', $base, [
            'name' => 'Round tables', 'pricing_unit' => 'per_unit', 'unit_label' => ' table ', 'price' => 500, 'min_quantity' => 2, 'max_quantity' => 8,
        ])->assertCreated()->assertJsonPath('data.unit_label', 'table')->assertJsonPath('data.min_quantity', 2)->assertJsonPath('data.max_quantity', 8);
        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Emcee', 'pricing_unit' => 'per_hour', 'price' => 2000, 'min_quantity' => 3])
            ->assertCreated()->assertJsonPath('data.max_quantity', null);
        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Venue', 'pricing_unit' => 'per_day', 'price' => 30000])->assertCreated();

        // Most below least is refused.
        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Bad range', 'pricing_unit' => 'per_unit', 'price' => 1, 'min_quantity' => 5, 'max_quantity' => 2])
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['max_quantity']]]]);

        // Estimate settings on the profile, and everything on the public page.
        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/profile", [
            'estimate_service_charge_percent' => 10, 'estimate_tax_percent' => 12, 'estimate_note' => 'Final price after a site visit.',
        ])->assertOk()->assertJsonPath('data.estimate_tax_percent', 12);
        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/profile", ['estimate_tax_percent' => 25])->assertUnprocessable();

        $slug = DB::table('organizations')->where('id', $org->id)->value('slug');
        $public = $this->spaJson('GET', "/api/v1/marketplace/organizers/{$slug}")->assertOk();
        $public->assertJsonPath('data.estimate.service_charge_percent', 10)->assertJsonPath('data.estimate.note', 'Final price after a site visit.');
        $tables = collect($public->json('data.price_options'))->firstWhere('name', 'Round tables');
        $this->assertSame(['per_unit', 'table', 2, 8], [$tables['pricing_unit'], $tables['unit_label'], $tables['min_quantity'], $tables['max_quantity']]);
    }

    public function test_options_stay_inside_their_organization(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$other, $otherOwner] = $this->listedOrganizer('Other', [], ['Lights']);
        $foreignService = DB::table('organization_services')->where('organization_id', $other->id)->value('id');
        $mine = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/price-options", ['name' => 'Package', 'pricing_unit' => 'flat', 'price' => 100])->json('data.id');

        // Another organization's service can't group my option.
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/price-options", [
            'organization_service_id' => $foreignService, 'name' => 'Borrowed', 'pricing_unit' => 'flat', 'price' => 1,
        ])->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['organization_service_id']]]]);

        // Nor can another owner edit it, via either organization's URL (RLS
        // hides organizations you are not a member of: 404, not 403).
        $this->actingAs($otherOwner)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/price-options/{$mine}", ['price' => 1])->assertNotFound();
        $this->actingAs($otherOwner)->spaJson('PATCH', "/api/v1/organizations/{$other->id}/price-options/{$mine}", ['price' => 1])->assertNotFound();

        // The database enforces the same-organization rule as well (0033 trigger).
        $this->apiRoute('POST', 'borrow/{option}/{service}', fn (string $option, string $service) => ApiResponse::success(
            DB::table('organization_price_options')->where('id', $option)->update(['organization_service_id' => $service]),
        ), ['auth:sanctum']);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/_test/borrow/{$mine}/{$foreignService}")->assertUnprocessable();
    }

    public function test_public_profile_shows_only_active_options_of_listed_organizers(): void
    {
        [$org, $owner] = $this->listedOrganizer('Calc Co');
        $base = "/api/v1/organizations/{$org->id}/price-options";
        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Visible', 'pricing_unit' => 'per_guest', 'price' => 300, 'sort_order' => 1]);
        $this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Hidden', 'pricing_unit' => 'flat', 'price' => 5, 'is_active' => false]);
        $slug = DB::table('organizations')->where('id', $org->id)->value('slug');

        $this->spaJson('GET', "/api/v1/marketplace/organizers/{$slug}")->assertOk()
            ->assertJsonCount(1, 'data.price_options')
            ->assertJsonPath('data.price_options.0.name', 'Visible')
            ->assertJsonPath('data.price_options.0.pricing_unit', 'per_guest');

        // Unpublishing hides the organizer (and so its price list).
        DB::table('organization_profiles')->where('organization_id', $org->id)->update(['is_published' => false]);
        $this->assertSame(0, DB::table('public_organization_price_options')->where('organization_id', $org->id)->count());
    }

    public function test_staff_can_view_but_not_edit_and_guests_have_no_table_access(): void
    {
        [$org] = $this->listedOrganizer();
        $this->addStaff($org, $staff = $this->businessUser());

        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/price-options")->assertOk();
        $this->actingAs($staff)->spaJson('POST', "/api/v1/organizations/{$org->id}/price-options", ['name' => 'Nope', 'pricing_unit' => 'flat', 'price' => 1])->assertForbidden();

        $this->assertFalse((bool) DB::scalar("select has_table_privilege('anon', 'organization_price_options', 'SELECT')"));
        $this->assertFalse((bool) DB::scalar("select has_table_privilege('authenticated', 'public_organization_price_options', 'SELECT')"));
    }
}
