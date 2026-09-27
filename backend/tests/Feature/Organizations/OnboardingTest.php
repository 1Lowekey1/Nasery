<?php

namespace Tests\Feature\Organizations;

use App\Models\OrganizationMember;
use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DefinesTestRoutes;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use DefinesTestRoutes, InteractsWithOrganizations;

    public function test_business_account_creates_its_organization_pending_review(): void
    {
        $owner = $this->businessUser();

        $response = $this->actingAs($owner)->spaJson('POST', '/api/v1/organizations', $this->onboardingPayload(['display_name' => 'Alter Moderne Events']))
            ->assertCreated()
            ->assertJsonPath('data.display_name', 'Alter Moderne Events')
            ->assertJsonPath('data.approval_status', 'pending')
            ->assertJsonPath('data.is_operational', false)
            ->assertJsonPath('data.my_membership.is_owner', true)
            ->assertJsonPath('data.my_membership.is_primary_owner', true)
            ->assertJsonPath('data.profile.contact_phone', '+639175550101')
            ->assertJsonPath('data.profile.is_published', false);

        $orgId = $response->json('data.id');
        $this->assertStringStartsWith('alter-moderne-events', $response->json('data.slug'));

        $this->assertDatabaseHas('organization_members', ['organization_id' => $orgId, 'user_id' => $owner->id, 'is_primary_owner' => true, 'is_owner' => true]);
        $this->assertDatabaseHas('ownership_transfers', ['organization_id' => $orgId, 'from_member_id' => null, 'initiated_by' => $owner->id]);
        $this->assertDatabaseHas('organizer_approval_requests', ['organization_id' => $orgId, 'status' => 'pending', 'reviewed_by' => null]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.created', 'organization_id' => $orgId, 'actor_user_id' => $owner->id]);
    }

    public function test_only_verified_business_accounts_can_onboard(): void
    {
        $this->actingAs(User::factory()->create())->spaJson('POST', '/api/v1/organizations', $this->onboardingPayload())
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_TYPE_NOT_ALLOWED');

        $this->actingAs($this->businessUser(['email_verified_at' => null]))->spaJson('POST', '/api/v1/organizations', $this->onboardingPayload())
            ->assertForbidden()->assertJsonPath('error.code', 'EMAIL_NOT_VERIFIED');
    }

    public function test_one_owned_organization_per_account(): void
    {
        $owner = $this->businessUser();
        $this->onboard($owner);

        $this->actingAs($owner)->spaJson('POST', '/api/v1/organizations', $this->onboardingPayload())
            ->assertConflict()->assertJsonPath('error.code', 'ORGANIZATION_EXISTS');
    }

    public function test_slugs_are_unique_across_tenants(): void
    {
        $first = $this->onboard($this->businessUser(), ['display_name' => 'Cagata Events Test Slug']);
        $second = $this->onboard($this->businessUser(), ['display_name' => 'Cagata Events Test Slug']);

        $this->assertNotSame($first->slug, $second->slug);
        $this->assertSame($first->slug.'-2', $second->slug);
    }

    public function test_validation(): void
    {
        $owner = $this->businessUser();

        $this->actingAs($owner)->spaJson('POST', '/api/v1/organizations', ['legal_name' => 'X'])
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['display_name', 'profile']]]]);

        $this->actingAs($owner)->spaJson('POST', '/api/v1/organizations', $this->onboardingPayload([
            'profile' => ['contact_phone' => '123', 'latitude' => 7.1, 'longitude' => null, 'contact_email' => 'not-an-email'],
        ]))->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['profile.contact_phone', 'profile.longitude', 'profile.contact_email']]]]);
    }

    public function test_tenants_are_isolated(): void
    {
        $mine = $this->onboard($owner = $this->businessUser());
        $theirs = $this->onboard($this->businessUser());

        $this->actingAs($owner)->spaJson('GET', '/api/v1/organizations/mine')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);

        // Another tenant's organization does not exist for me (RLS), even by id.
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$theirs->id}")->assertNotFound();
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$theirs->id}/profile")->assertNotFound();
        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$theirs->id}", ['display_name' => 'Hijacked'])->assertNotFound();
    }

    public function test_owner_can_rename_and_change_slug_but_not_to_a_taken_one(): void
    {
        $mine = $this->onboard($owner = $this->businessUser());
        $theirs = $this->onboard($this->businessUser());

        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$mine->id}", ['display_name' => 'Renamed Co', 'slug' => 'renamed-co-'.substr($mine->id, 0, 8)])
            ->assertOk()->assertJsonPath('data.display_name', 'Renamed Co');

        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$mine->id}", ['slug' => $theirs->slug])
            ->assertUnprocessable()->assertJsonPath('error.details.fields.slug.0', 'That address is already taken.');

        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$mine->id}", ['slug' => 'Bad Slug!'])->assertUnprocessable();
    }

    public function test_staff_can_view_but_not_manage(): void
    {
        $org = $this->onboard($this->businessUser());
        $this->addStaff($org, $staff = $this->businessUser());

        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}")
            ->assertOk()->assertJsonPath('data.my_membership.is_owner', false);
        $this->actingAs($staff)->spaJson('PATCH', "/api/v1/organizations/{$org->id}", ['display_name' => 'Staff Rename'])
            ->assertForbidden();
        $this->actingAs($staff)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/profile", ['description' => 'x'])
            ->assertForbidden();
    }

    public function test_database_refuses_self_approval_even_if_laravel_were_bypassed(): void
    {
        $org = $this->onboard($owner = $this->businessUser());

        // Simulates a Laravel bug that let raw SQL through as the member.
        $this->apiRoute('POST', 'self-approve/{id}', fn (string $id) => ApiResponse::success(
            DB::update("update organizations set approval_status = 'approved' where id = ?", [$id]),
        ), ['auth:sanctum']);

        $this->actingAs($owner)->spaJson('POST', "/api/v1/_test/self-approve/{$org->id}")
            ->assertForbidden(); // 42501: column not writable by nasery_app (0028)

        $this->assertSame('pending', DB::table('organizations')->where('id', $org->id)->value('approval_status'));
    }

    public function test_database_refuses_self_promotion_to_owner(): void
    {
        $org = $this->onboard($this->businessUser());
        $this->addStaff($org, $staff = $this->businessUser());

        $this->apiRoute('POST', 'self-promote', fn () => ApiResponse::success(
            DB::update('update organization_members set is_owner = true where user_id = ?', [auth()->id()]),
        ), ['auth:sanctum']);

        $this->actingAs($staff)->spaJson('POST', '/api/v1/_test/self-promote')->assertForbidden();

        $this->assertFalse((bool) OrganizationMember::query()->where('user_id', $staff->id)->value('is_owner'));
    }
}
