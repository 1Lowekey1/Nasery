<?php

namespace Tests\Concerns;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

trait InteractsWithOrganizations
{
    use InteractsWithAuth;

    protected function businessUser(array $attributes = []): User
    {
        return User::factory()->business()->create($attributes);
    }

    protected function adminUser(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    protected function onboardingPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'legal_name' => 'Creative Kings Events Management Inc.',
            'display_name' => 'Creative Kings '.Str::random(5),
            'profile' => [
                'business_name' => 'Creative Kings Event Management',
                'description' => 'Weddings and corporate events in Davao.',
                'location_text' => 'Obrero, Davao City',
                'latitude' => 7.0831,
                'longitude' => 125.6127,
                'contact_email' => 'hello@creativekings.test',
                'contact_phone' => '0917 555 0101',
            ],
        ], $overrides);
    }

    /** Onboard through the real API and return the organization. */
    protected function onboard(User $owner, array $overrides = []): Organization
    {
        $id = $this->actingAs($owner)->spaJson('POST', '/api/v1/organizations', $this->onboardingPayload($overrides))
            ->assertCreated()
            ->json('data.id');

        $organization = Organization::query()->findOrFail($id);
        $this->onPlan($organization, $this->planForTests());

        return $organization;
    }

    /**
     * B18: plan for organizations made by these helpers. Pro (unlimited) so
     * feature tests aren't cut short by Free's limits; billing tests override
     * this to 'free'.
     */
    protected function planForTests(): string
    {
        return 'pro';
    }

    /** Put an organization on a plan directly (the checkout itself is tested in BillingTest). */
    protected function onPlan(Organization $organization, string $slug): void
    {
        DB::table('subscriptions')->where('organization_id', $organization->id)->whereIn('status', ['active', 'payment_failed', 'grace_period'])
            ->update(['status' => 'cancelled', 'cancelled_at' => now(), 'grace_period_ends_at' => null]);
        if ($slug === 'free') {
            return;
        }
        DB::table('subscriptions')->insert([
            'id' => (string) Str::uuid(), 'organization_id' => $organization->id,
            'plan_id' => DB::table('plans')->where('slug', $slug)->value('id'), 'billing_cycle' => 'monthly', 'status' => 'active',
            'current_period_start' => now(), 'current_period_end' => now()->addMonth(),
        ]);
    }

    /**
     * An approved, published organizer (as after admin approval), optionally
     * with services. Returns [organization, owner].
     *
     * @param  list<string>  $services
     * @return array{0: Organization, 1: User}
     */
    protected function listedOrganizer(string $name = 'Listed Co', array $profile = [], array $services = []): array
    {
        $owner = $this->businessUser();
        $org = $this->onboard($owner, ['display_name' => $name.' '.Str::random(4), 'profile' => $profile]);

        foreach ($services as $service) {
            $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/services", ['custom_name' => $service]);
        }

        DB::table('organizations')->where('id', $org->id)->update(['approval_status' => 'approved']);
        DB::table('organization_profiles')->where('organization_id', $org->id)->update(['is_published' => true]);

        return [$org->refresh(), $owner];
    }

    /**
     * Put a staff member on an event's team through the real API (B8),
     * optionally with event-only permissions. Returns the event member id.
     *
     * @param  list<string>  $permissions
     */
    protected function assignToEvent(User $owner, Organization $organization, string $eventId, User $staff, array $permissions = []): string
    {
        $memberId = DB::table('organization_members')->where('organization_id', $organization->id)->where('user_id', $staff->id)->value('id');

        return collect($this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$organization->id}/events/{$eventId}/team", [
            'organization_member_id' => $memberId, 'permissions' => $permissions,
        ])->assertCreated()->json('data'))->firstWhere('organization_member_id', $memberId)['id'];
    }

    /** Add a non-owner staff member directly (the invite flow itself is tested in StaffTest). */
    protected function addStaff(Organization $organization, User $user): void
    {
        DB::table('organization_members')->insert([
            'id' => (string) Str::uuid(),
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
