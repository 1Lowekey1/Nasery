<?php

namespace Tests\Feature\Organizations;

use App\Support\Http\ApiResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DefinesTestRoutes;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ApprovalWorkflowTest extends TestCase
{
    use DefinesTestRoutes, InteractsWithOrganizations;

    private function decide(string $orgId, string $status, ?string $reason = null)
    {
        return $this->actingAs($this->admin)->spaJson('POST', "/api/v1/admin/organizer-applications/{$orgId}/decision", array_filter([
            'status' => $status, 'reason' => $reason,
        ]));
    }

    private $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->adminUser();
    }

    public function test_an_admin_note_reaches_the_owner_without_a_decision(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $url = "/api/v1/admin/organizer-applications/{$org->id}/note";
        $key = fn () => ['Idempotency-Key' => (string) Str::uuid()];

        $this->actingAs($owner)->spaJson('POST', $url, ['note' => 'Hello there'], $key())->assertForbidden();

        $this->actingAs($this->admin)->spaJson('POST', $url, ['note' => 'Your permit is blurry.'], $key())->assertOk()
            ->assertJsonPath('data.approval_status', 'pending');

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/approval")->assertOk()
            ->assertJsonPath('data.approval_status', 'pending')
            ->assertJsonFragment(['kind' => 'note', 'decision_reason' => 'Your permit is blurry.']);

        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $owner->id, 'type' => 'application.note', 'body' => 'Your permit is blurry.']);

        $this->decide($org->id, 'rejected', 'Please fix your profile.')->assertOk();
        $this->actingAs($this->admin)->spaJson('POST', $url, ['note' => 'One more thing.'], $key())->assertConflict();
    }

    public function test_only_admins_see_applications(): void
    {
        $org = $this->onboard($owner = $this->businessUser());

        $this->actingAs($owner)->spaJson('GET', '/api/v1/admin/organizer-applications')
            ->assertForbidden()->assertJsonPath('error.code', 'ACCOUNT_TYPE_NOT_ALLOWED');

        $this->actingAs($this->admin)->spaJson('GET', '/api/v1/admin/organizer-applications?status=pending')
            ->assertOk()
            ->assertJsonFragment(['id' => $org->id, 'approval_status' => 'pending']);
    }

    public function test_admin_review_shows_what_was_submitted(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/services", ['custom_name' => 'Debut Packages']);

        $this->actingAs($this->admin)->spaJson('GET', "/api/v1/admin/organizer-applications/{$org->id}")
            ->assertOk()
            ->assertJsonPath('data.owner.email', $owner->email)
            ->assertJsonPath('data.owner.email_verified', true)
            ->assertJsonPath('data.profile.business_name', 'Creative Kings Event Management')
            ->assertJsonPath('data.services.0.name', 'Debut Packages')
            ->assertJsonPath('data.allowed_decisions', ['under_review', 'approved', 'rejected'])
            ->assertJsonPath('data.history.0.kind', 'submission');
    }

    public function test_approval_makes_the_organization_operational(): void
    {
        $org = $this->onboard($owner = $this->businessUser());

        $this->decide($org->id, 'under_review')->assertOk()->assertJsonPath('data.approval_status', 'under_review');
        $this->decide($org->id, 'approved')->assertOk()->assertJsonPath('data.allowed_decisions', ['suspended']);

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/approval")
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'approved')
            ->assertJsonPath('data.is_operational', true)
            ->assertJsonCount(3, 'data.history');

        // Written by set_organizer_approval_status() itself.
        $this->assertDatabaseHas('audit_logs', ['action' => 'organizer_approval_status_change', 'organization_id' => $org->id, 'actor_user_id' => $this->admin->id]);
    }

    public function test_rejection_needs_a_reason_and_can_be_resubmitted(): void
    {
        $org = $this->onboard($owner = $this->businessUser());

        $this->decide($org->id, 'rejected')->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['reason']]]]);

        $this->decide($org->id, 'rejected', 'Please email your business permit.')->assertOk()
            ->assertJsonPath('data.approval_status', 'rejected');

        // (Order isn't asserted: inside the test's wrapping transaction the DB
        // function's now() is frozen at test start, unlike in production.)
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/approval")
            ->assertJsonPath('data.can_resubmit', true)
            ->assertJsonFragment(['status' => 'rejected', 'decision_reason' => 'Please email your business permit.', 'kind' => 'decision']);

        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/approval/resubmit")
            ->assertOk()->assertJsonPath('data.approval_status', 'pending');

        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/approval/resubmit")
            ->assertConflict()->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION');

        $this->assertDatabaseHas('audit_logs', ['action' => 'organization.approval_resubmitted', 'organization_id' => $org->id]);
    }

    public function test_invalid_transitions_are_refused(): void
    {
        $org = $this->onboard($this->businessUser());
        $this->decide($org->id, 'approved')->assertOk();

        $this->decide($org->id, 'rejected', 'late change')
            ->assertConflict()
            ->assertJsonPath('error.code', 'INVALID_STATE_TRANSITION')
            ->assertJsonPath('error.details.allowed', ['suspended']);
    }

    public function test_suspension_and_reinstatement(): void
    {
        $org = $this->onboard($owner = $this->businessUser());
        $this->decide($org->id, 'approved');

        $this->decide($org->id, 'suspended')->assertUnprocessable();
        $this->decide($org->id, 'suspended', 'Complaints under investigation')->assertOk()
            ->assertJsonPath('data.is_suspended', true);

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}")
            ->assertJsonPath('data.is_operational', false)
            ->assertJsonPath('data.suspended_reason', 'Complaints under investigation');

        $this->decide($org->id, 'approved')->assertOk()->assertJsonPath('data.is_suspended', false);
    }

    public function test_staff_cannot_resubmit_for_the_owner(): void
    {
        $org = $this->onboard($this->businessUser());
        $this->addStaff($org, $staff = $this->businessUser());
        $this->decide($org->id, 'rejected', 'Incomplete');

        $this->actingAs($staff)->spaJson('POST', "/api/v1/organizations/{$org->id}/approval/resubmit")->assertForbidden();
    }

    public function test_decision_is_idempotent_on_retry(): void
    {
        $org = $this->onboard($this->businessUser());
        $key = (string) Str::uuid();

        $first = $this->actingAs($this->admin)->spaJson('POST', "/api/v1/admin/organizer-applications/{$org->id}/decision", ['status' => 'approved'], ['Idempotency-Key' => $key])->assertOk();
        $retry = $this->actingAs($this->admin)->spaJson('POST', "/api/v1/admin/organizer-applications/{$org->id}/decision", ['status' => 'approved'], ['Idempotency-Key' => $key])->assertOk();

        $retry->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(1, DB::table('organizer_approval_requests')->where('organization_id', $org->id)->where('status', 'approved')->count());
    }

    public function test_database_function_refuses_non_admin_callers(): void
    {
        $org = $this->onboard($owner = $this->businessUser());

        $this->apiRoute('POST', 'call-approve/{id}', fn (string $id) => ApiResponse::success(
            DB::select("select set_organizer_approval_status(?, 'approved', null)", [$id]),
        ), ['auth:sanctum']);

        $this->actingAs($owner)->spaJson('POST', "/api/v1/_test/call-approve/{$org->id}")
            ->assertConflict()->assertJsonPath('error.code', 'BUSINESS_RULE_VIOLATION');

        $this->assertSame('pending', DB::table('organizations')->where('id', $org->id)->value('approval_status'));
    }

    public function test_unknown_organization_is_not_found_for_admin(): void
    {
        $this->actingAs($this->admin)->spaJson('GET', '/api/v1/admin/organizer-applications/'.Str::uuid())->assertNotFound();
        $this->actingAs($this->admin)->spaJson('GET', '/api/v1/admin/organizer-applications/not-a-uuid')->assertNotFound();
    }
}
