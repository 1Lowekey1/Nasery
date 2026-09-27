<?php

namespace Tests\Feature\Reviews;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ReviewTest extends TestCase
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

    /** $client's inquiry won by $org and converted into a published event; returns [eventId, base]. */
    private function bookedEvent(User $client, Organization $org, User $owner): array
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

        return [$event, $base];
    }

    private function complete(User $owner, string $base): void
    {
        $this->travelTo(now()->addHours(2));
        $this->actingAs($owner)->spaJson('POST', "{$base}/complete")->assertOk();
    }

    public function test_only_the_booking_client_reviews_a_completed_event_once(): void
    {
        $client = User::factory()->create(['first_name' => 'Ella', 'last_name' => 'Reyes']);
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->bookedEvent($client, $org, $owner);

        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/review")->assertOk()
            ->assertJsonPath('data.can_review', false)->assertJsonPath('data.reason', 'You can review the event once your organizer marks it completed.');
        $this->actingAs($client)->spaJson('POST', "/api/v1/events/{$event}/review", ['rating' => 5], $this->key())->assertConflict();

        $this->complete($owner, $base);
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $client->id)->where('type', 'review.requested')->count());

        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/review")->assertJsonPath('data.can_review', true);
        $this->actingAs($client)->spaJson('POST', "/api/v1/events/{$event}/review", ['rating' => 6], $this->key())->assertUnprocessable();
        $this->actingAs($client)->spaJson('POST', "/api/v1/events/{$event}/review", ['rating' => 5, 'written_review' => '  Flawless coordination!  '], $this->key())
            ->assertCreated()->assertJsonPath('data.rating', 5)->assertJsonPath('data.written_review', 'Flawless coordination!');
        $this->actingAs($client)->spaJson('POST', "/api/v1/events/{$event}/review", ['rating' => 1], $this->key())->assertConflict();
        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/review")->assertJsonPath('data.can_review', false)->assertJsonPath('data.review.rating', 5);

        // Public profile: rating and the review, first name + last initial only.
        $this->getJson("/api/v1/marketplace/organizers/{$org->slug}")->assertOk()
            ->assertJsonPath('data.rating.average', 5)->assertJsonPath('data.rating.count', 1)
            ->assertJsonPath('data.recent_reviews.0.reviewer', 'Ella R.')->assertJsonPath('data.recent_reviews.0.reply', null);
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'review.received')->count());

        // Nobody else can review it: other clients can't even see the event.
        $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/events/{$event}/review", ['rating' => 1], $this->key())->assertNotFound();
    }

    public function test_edits_keep_history_for_30_days_and_the_owner_replies_publicly(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->bookedEvent($client, $org, $owner);
        $this->complete($owner, $base);
        $review = $this->actingAs($client)->spaJson('POST', "/api/v1/events/{$event}/review", ['rating' => 3, 'written_review' => 'Late start.'], $this->key())->json('data.id');

        $this->actingAs($client)->spaJson('PATCH', "/api/v1/events/{$event}/review", ['rating' => 4, 'written_review' => 'Late start, great party.'])
            ->assertOk()->assertJsonPath('data.edited', true)->assertJsonPath('data.rating', 4);
        $this->assertDatabaseHas('review_edits', ['review_id' => $review, 'previous_rating' => 3, 'previous_written_review' => 'Late start.']);

        // Only the owner replies; the reply is public and the client is told.
        $staff = $this->businessUser();
        $this->addStaff($org, $staff);
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/reviews")->assertOk()->assertJsonPath('data.can.reply', false)
            ->assertJsonPath('data.summary.awaiting_reply', 1);
        $this->actingAs($staff)->spaJson('PUT', "/api/v1/organizations/{$org->id}/reviews/{$review}/reply", ['text' => 'Sorry!'])->assertForbidden();
        $this->actingAs($owner)->spaJson('PUT', "/api/v1/organizations/{$org->id}/reviews/{$review}/reply", ['text' => 'Thank you! We fixed our call times.'])
            ->assertOk()->assertJsonPath('data.reply.text', 'Thank you! We fixed our call times.');
        $this->getJson("/api/v1/marketplace/organizers/{$org->slug}")
            ->assertJsonPath('data.recent_reviews.0.reply.text', 'Thank you! We fixed our call times.')->assertJsonPath('data.recent_reviews.0.edited', true);
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $client->id)->where('type', 'review.replied')->count());
        $this->actingAs($owner)->spaJson('DELETE', "/api/v1/organizations/{$org->id}/reviews/{$review}/reply")->assertOk();
        $this->getJson("/api/v1/marketplace/organizers/{$org->slug}")->assertJsonPath('data.recent_reviews.0.reply', null);

        // After 30 days it's final.
        $this->travelTo(now()->addDays(31));
        $this->actingAs($client)->spaJson('PATCH', "/api/v1/events/{$event}/review", ['rating' => 1])->assertConflict();
        $this->actingAs($client)->spaJson('DELETE', "/api/v1/events/{$event}/review")->assertConflict();
    }

    public function test_reports_reach_the_admin_without_hiding_the_review(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->bookedEvent($client, $org, $owner);
        $this->complete($owner, $base);
        $review = $this->actingAs($client)->spaJson('POST', "/api/v1/events/{$event}/review", ['rating' => 1, 'written_review' => 'Terrible.'], $this->key())->json('data.id');

        $this->actingAs($owner)->spaJson('POST', "/api/v1/reviews/{$review}/report", ['reason' => 'This client never attended.'])->assertOk();
        $this->actingAs($owner)->spaJson('POST', "/api/v1/reviews/{$review}/report", ['reason' => 'Reporting again.'])->assertConflict();
        $this->actingAs($client)->spaJson('POST', "/api/v1/reviews/{$review}/report", ['reason' => 'Report my own'])->assertConflict();

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/reviews")
            ->assertJsonPath('data.reviews.0.open_reports', 1)->assertJsonPath('data.reviews.0.status', 'published');
        $this->getJson("/api/v1/marketplace/organizers/{$org->slug}")->assertJsonPath('data.rating.count', 1);

        // The client may still remove it within 30 days.
        $this->actingAs($client)->spaJson('DELETE', "/api/v1/events/{$event}/review")->assertOk();
        $this->getJson("/api/v1/marketplace/organizers/{$org->slug}")->assertJsonPath('data.rating.count', 0);
        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/review")->assertJsonPath('data.can_review', true);
    }
}
