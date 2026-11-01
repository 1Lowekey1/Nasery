<?php

namespace Tests\Feature\Admin;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function notified(User $user, string $type): int
    {
        return DB::table('notifications')->where('recipient_user_id', $user->id)->where('type', $type)->count();
    }

    /** A completed event booked by $client through $org; returns the event id. */
    private function completedBooking(User $client, Organization $org, User $owner): string
    {
        $inquiry = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(), 'guest_count' => 90, 'location_text' => 'Lanang, Davao City',
        ])->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$inquiry}/submit", ['mode' => 'direct', 'organization_ids' => [$org->id]], $this->key())->assertOk();
        $quotation = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$inquiry}/quotations",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 50000]], 'submit' => true], $this->key())->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/quotations/{$quotation}/accept", [], $this->key())->assertOk();
        $start = now('Asia/Manila')->addHour();
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/quotations/{$quotation}/event", [
            'name' => 'Reyes Wedding', 'sessions' => [['starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->addHours(4)->format('Y-m-d\TH:i')]],
        ], $this->key())->assertCreated()->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();
        $this->travelTo(now()->addHours(2));
        $this->actingAs($owner)->spaJson('POST', "{$base}/complete")->assertOk();

        return $event;
    }

    /** A published public event with a paid tier and one settled ₱1,600 purchase; returns [org, owner, event, paymentId]. */
    private function paidPurchase(): array
    {
        [$org, $owner] = $this->listedOrganizer('Paid Co');
        $day = now('Asia/Manila')->addDays(30)->format('Y-m-d');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Paid Gig', 'visibility' => 'public', 'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T22:00"]],
        ], $this->key())->json('data.id');
        $tier = collect($this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/ticket-types",
            ['name' => 'VIP', 'price' => 800, 'capacity' => 20, 'status' => 'on_sale'])->json('data'))->firstWhere('name', 'VIP')['id'];
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();

        return [$org, $owner, $event, $this->pay($event, $tier), $tier];
    }

    private function pay(string $event, string $tier): string
    {
        $res = $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/public-events/{$event}/register", [
            'ticket_type_id' => $tier, 'attendees' => [['full_name' => 'Guest 1'], ['full_name' => 'Guest 2']],
        ], $this->key())->assertCreated()->json('data');
        parse_str((string) parse_url($res['checkout']['checkout_url'], PHP_URL_QUERY), $q);
        $intent = DB::table('payments')->where('id', $q['payment'])->value('paymongo_payment_intent_id');
        $this->postJson($q['action'], ['outcome' => 'paid', 'intent' => $intent])->assertOk();

        return $res['checkout']['payment_id'];
    }

    public function test_workspace_access_needs_the_organizers_consent(): void
    {
        $admin = $this->adminUser();
        [$org, $owner] = $this->listedOrganizer();
        $staff = $this->businessUser();
        $this->addStaff($org, $staff);
        $workspace = "/api/v1/admin/organizations/{$org->id}/workspace";

        // Totals are open; the workspace is not.
        $this->actingAs($admin)->spaJson('GET', "/api/v1/admin/organizer-applications/{$org->id}")->assertOk()
            ->assertJsonPath('data.totals.events', 0)->assertJsonPath('data.access.grant', null);
        $this->actingAs($admin)->spaJson('GET', $workspace)->assertForbidden();
        $this->actingAs($owner)->spaJson('GET', '/api/v1/admin/overview')->assertForbidden();

        // Ask → pending never opens anything → the owner refuses.
        $req = $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/organizations/{$org->id}/access-requests", ['reason' => 'Checking a payment complaint'])
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/organizations/{$org->id}/access-requests", ['reason' => 'Asking twice'])->assertConflict();
        $this->assertSame(1, $this->notified($owner, 'access.requested'));
        $this->actingAs($admin)->spaJson('GET', $workspace)->assertForbidden();
        $this->assertSame(0, DB::table('admin_access_grants')->where('organization_id', $org->id)->count());

        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/nasery-access")->assertOk()
            ->assertJsonPath('data.can.manage', false)->assertJsonPath('data.pending.0.reason', 'Checking a payment complaint');
        $this->actingAs($staff)->spaJson('POST', "/api/v1/organizations/{$org->id}/nasery-access/requests/{$req}/decision", ['approve' => true])->assertForbidden();
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/nasery-access/requests/{$req}/decision", ['approve' => false, 'note' => 'Not now'])
            ->assertOk()->assertJsonPath('data.status', 'declined');
        $this->assertSame(1, $this->notified($admin, 'admin.access'));

        // Ask again → approved: read-only view for the default 24 hours, granted by the owner.
        $req = $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/organizations/{$org->id}/access-requests", ['reason' => 'Checking a payment complaint'])->json('data.id');
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/nasery-access/requests/{$req}/decision", ['approve' => true])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.grant.active', true);
        $grant = DB::table('admin_access_grants')->where('organization_id', $org->id)->first();
        $this->assertSame($owner->id, $grant->granted_by);
        $this->assertEqualsWithDelta(24 * 3600, strtotime($grant->expires_at) - strtotime($grant->starts_at), 5);
        $this->actingAs($admin)->spaJson('GET', $workspace)->assertOk()
            ->assertJsonPath('data.team.0.role', 'Primary owner')->assertJsonPath('data.payments.sales', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'admin_access.viewed', 'organization_id' => $org->id]);

        // The owner ends it anytime.
        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/nasery-access/grants/{$grant->id}")->assertOk();
        $this->actingAs($admin)->spaJson('GET', $workspace)->assertForbidden();

        // Support invite from the owner → any admin accepts → consent already given.
        $before = $this->notified($admin, 'admin.access');
        $invite = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/nasery-access/invite", ['reason' => 'Checkout keeps failing', 'hours' => 4])
            ->assertCreated()->json('data.id');
        $this->assertSame($before + 1, $this->notified($admin, 'admin.access'));
        $invites = collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/access')->assertOk()->json('data.invites'))->keyBy('id');
        $this->assertSame('Checkout keeps failing', $invites[$invite]['reason']);
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/access-requests/{$invite}/accept")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame($owner->id, DB::table('admin_access_grants')->where('organization_id', $org->id)->whereNull('revoked_at')->value('granted_by'));
        $this->assertSame(2, $this->notified($owner, 'access.update')); // ended earlier + started now
        $this->actingAs($admin)->spaJson('GET', $workspace)->assertOk();
        $this->travelTo(now()->addHours(5));
        $this->actingAs($admin)->spaJson('GET', $workspace)->assertForbidden();

        // Unanswered requests lapse after a week.
        $req = $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/organizations/{$org->id}/access-requests", ['reason' => 'Routine check'])->json('data.id');
        $this->travelTo(now()->addDays(8));
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/nasery-access/requests/{$req}/decision", ['approve' => true])->assertStatus(409);
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/nasery-access")->assertOk()
            ->assertJsonCount(0, 'data.pending')->assertJsonPath('data.history.0.id', $req)->assertJsonPath('data.history.0.status', 'expired');
    }

    public function test_reported_reviews_are_taken_kept_hidden_and_restored(): void
    {
        $admin = $this->adminUser();
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->completedBooking($client, $org, $owner);
        $review = $this->actingAs($client)->spaJson('POST', "/api/v1/events/{$event}/review", ['rating' => 1, 'written_review' => 'Terrible.'], $this->key())->json('data.id');
        $reporter = User::factory()->create();
        $public = fn () => $this->getJson("/api/v1/marketplace/organizers/{$org->slug}")->json('data.rating.count');

        // Counts are platform-wide (other data may exist): compare against a baseline.
        $open = $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/moderation/reviews')->json('data.counts.open');
        $inReview = (int) DB::table('reviews')->where('status', 'disputed')->count();
        $this->actingAs($reporter)->spaJson('POST', "/api/v1/reviews/{$review}/report", ['reason' => 'Fake review, never booked.'])->assertOk();
        $this->assertSame(1, $this->notified($admin, 'admin.report'));
        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/moderation/reviews')->assertOk()
            ->assertJsonPath('data.counts.open', $open + 1)->assertJsonPath('data.items.0.reports.0.reason', 'Fake review, never booked.');

        // Take the case: disputed, hidden meanwhile, the client is told.
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/moderation/reviews/{$review}/start", ['note' => 'Checking the booking'])->assertOk()
            ->assertJsonPath('data.in_review', $inReview + 1);
        $this->assertSame('disputed', DB::table('reviews')->where('id', $review)->value('status'));
        $this->assertSame(0, $public());
        $this->assertSame(1, $this->notified($client, 'review.moderated'));

        // Keep: back on the profile, the reporter hears back.
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/moderation/reviews/{$review}/decision", ['decision' => 'keep'])->assertUnprocessable();
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/moderation/reviews/{$review}/decision", ['decision' => 'keep', 'reason' => 'The client did book this event.'])->assertOk();
        $this->assertSame(1, $public());
        $this->assertSame(1, $this->notified($reporter, 'review.moderated'));
        $this->assertSame('dismissed', DB::table('review_reports')->where('review_id', $review)->value('status'));
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/moderation/reviews/{$review}/decision", ['decision' => 'hide', 'reason' => 'No report is open.'])->assertStatus(409);

        // A new report, decided straight away: hide, then restore.
        $this->actingAs($owner)->spaJson('POST', "/api/v1/reviews/{$review}/report", ['reason' => 'Contains a personal attack.'])->assertOk();
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/moderation/reviews/{$review}/decision", ['decision' => 'hide', 'reason' => 'Personal attack on staff.'])->assertOk();
        $this->assertSame(0, $public());
        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/moderation/reviews?queue=hidden')->assertJsonPath('data.items.0.id', $review)
            ->assertJsonPath('data.items.0.history.0.action', 'hidden');
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/moderation/reviews/{$review}/restore", ['reason' => 'Edited by the client on appeal.'])->assertOk();
        $this->assertSame(1, $public());
        $this->assertSame(3, DB::table('moderation_cases')->where('subject_id', $review)->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'review.hidden', 'entity_id' => $review]);
    }

    public function test_fee_claims_settings_and_the_platform_fee(): void
    {
        $admin = $this->adminUser();
        [$org, $owner, $event, $pay, $tier] = $this->paidPurchase();
        $refund = fn (int $amount, string $category) => $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/payments/{$pay}/refund",
            ['amount' => $amount, 'category' => $category, 'reason' => 'Typhoon signal 3', 'cancel_tickets' => false], $this->key())->assertOk();
        $pending = $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/moderation/fee-claims')->json('data.counts.pending');
        $refund(400, 'force_majeure');
        $refund(400, 'organizer_fault'); // keeps the fee: not a claim

        $claims = $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/moderation/fee-claims')->assertOk()->assertJsonPath('data.counts.pending', $pending + 1)
            ->assertJsonPath('data.items.0.category', 'Force majeure')->assertJsonPath('data.items.0.fee_returned', 4);
        $id = $claims->json('data.items.0.id');
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/moderation/fee-claims/{$id}", ['outcome' => 'flagged'])->assertUnprocessable();
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/moderation/fee-claims/{$id}", ['outcome' => 'flagged', 'note' => 'No typhoon that day.'])
            ->assertOk()->assertJsonPath('data.review.outcome', 'flagged');
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/moderation/fee-claims/{$id}", ['outcome' => 'accepted'])->assertConflict();
        $this->assertSame(1, $this->notified($owner, 'refund.claim_reviewed'));

        // Settings: bounded, and the checkout hold follows them.
        $this->actingAs($admin)->spaJson('PATCH', '/api/v1/admin/settings', ['ticket_hold_default_minutes' => 90, 'ticket_hold_max_minutes' => 60])->assertUnprocessable();
        $this->actingAs($admin)->spaJson('PATCH', '/api/v1/admin/settings', ['ticket_hold_default_minutes' => 3])->assertUnprocessable();
        $this->actingAs($admin)->spaJson('PATCH', '/api/v1/admin/settings', ['ticket_hold_default_minutes' => 20])->assertOk();
        $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/public-events/{$event}/register", [
            'ticket_type_id' => $tier, 'attendees' => [['full_name' => 'Hold Test']],
        ], $this->key())->assertCreated()->assertJsonPath('message', 'Places reserved for 20 minutes. Continue to payment.');

        // Fee: a new version from now on; older payments keep their snapshot.
        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/settings/fee', ['enabled' => true, 'model' => 'percentage', 'percent' => 2, 'reason' => 'Pilot pricing'])
            ->assertOk()->assertJsonPath('data.current.percent', 2);
        $second = $this->pay($event, $tier);
        $this->assertSame('32.00', DB::table('platform_fee_snapshots')->where('payment_id', $second)->value('computed_amount'));
        $this->assertSame('16.00', DB::table('platform_fee_snapshots')->where('payment_id', $pay)->value('computed_amount'));
        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/settings/fee', ['enabled' => false, 'reason' => 'Free month'])->assertOk()
            ->assertJsonPath('data.current.enabled', false);
        $third = $this->pay($event, $tier);
        $this->assertNull(DB::table('platform_fee_snapshots')->where('payment_id', $third)->value('computed_amount'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'platform.fee_changed', 'reason' => 'Pilot pricing']);

        // Platform numbers include the sales; the organizer's totals too.
        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/analytics?months=6')->assertOk()->assertJsonCount(6, 'data.trends.months');
        $this->assertGreaterThanOrEqual(4800, $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/overview')->json('data.totals.sales'));
        $this->actingAs($admin)->spaJson('GET', "/api/v1/admin/organizer-applications/{$org->id}")->assertJsonPath('data.totals.sales', 4800)
            ->assertJsonPath('data.totals.tickets_sold', 6);
        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/events?search=Paid%20Gig')->assertOk()->assertJsonPath('data.items.0.tickets_sold', 6);
    }

    public function test_accounts_catalog_announcements_and_activity(): void
    {
        $admin = $this->adminUser();
        $client = User::factory()->create(['first_name' => 'Suspend', 'last_name' => 'Me']);
        [$org, $owner] = $this->listedOrganizer();

        // Suspend: signed out everywhere, blocked, told; admins are off-limits.
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/users/{$client->id}/status", ['status' => 'suspended'])->assertUnprocessable();
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/users/{$client->id}/status", ['status' => 'suspended', 'reason' => 'Spam inquiries'])->assertOk();
        $this->actingAs($client->refresh())->spaJson('GET', '/api/v1/me/notifications')->assertForbidden();
        $this->assertSame(1, $this->notified($client, 'account.notice'));
        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/users?status=suspended&search=Suspend')->assertOk()->assertJsonPath('data.0.id', $client->id);
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/users/{$client->id}/status", ['status' => 'active', 'reason' => 'Appeal accepted'])->assertOk();
        $this->actingAs($client->refresh())->spaJson('GET', '/api/v1/me/notifications')->assertOk();
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/users/{$admin->id}/status", ['status' => 'suspended', 'reason' => 'Myself'])->assertConflict();

        // Catalog: organizers pick from it; archived services disappear from the picker.
        $svc = $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/services', ['name' => 'Lights & Sound Test', 'category' => 'Production'])->assertCreated()->json('data.id');
        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/services', ['name' => 'lights & sound test'])->assertUnprocessable();
        $catalog = fn () => collect($this->actingAs($owner)->spaJson('GET', '/api/v1/services')->json('data'))->pluck('name');
        $this->assertContains('Lights & Sound Test', $catalog());
        $this->actingAs($admin)->spaJson('DELETE', "/api/v1/admin/services/{$svc}")->assertOk();
        $this->assertNotContains('Lights & Sound Test', $catalog());
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/services/{$svc}/restore")->assertOk();

        // Announcements reach their audience only, within their dates.
        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/announcements', ['title' => 'Organizer webinar', 'body' => 'Friday 3 PM.', 'audience' => 'organizers'])->assertCreated();
        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/announcements', ['title' => 'Maintenance', 'body' => 'Sunday 1 AM.', 'audience' => 'all', 'ends_at' => now()->addDay()->toIso8601String()])->assertCreated();
        $this->actingAs($admin)->spaJson('POST', '/api/v1/admin/announcements', ['title' => 'Bad dates', 'body' => 'x x x', 'audience' => 'all', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->toIso8601String()])->assertUnprocessable();
        $titles = fn (?User $u) => collect(($u ? $this->actingAs($u) : $this)->getJson('/api/v1/announcements')->json('data'))->pluck('title')->all();
        $this->assertContains('Organizer webinar', $titles($owner));
        $this->assertNotContains('Organizer webinar', $titles($client));
        $this->app['auth']->forgetGuards();
        $this->assertSame(['Maintenance'], array_values(array_intersect($titles(null), ['Maintenance', 'Organizer webinar'])));
        $this->travelTo(now()->addDays(2));
        $this->assertNotContains('Maintenance', $titles($owner));

        // Activity: everything listed; an organizer's own details stay hidden without consent.
        $rows = collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/activity?actor=business')->assertOk()->json('data'));
        $orgRow = $rows->first(fn ($r) => ($r['organization']['id'] ?? null) === $org->id);
        $this->assertNotNull($orgRow);
        $this->assertTrue($orgRow['details_hidden']);
        $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/activity?search=user.suspended')->assertJsonPath('data.0.reason', 'Spam inquiries');
    }
}
