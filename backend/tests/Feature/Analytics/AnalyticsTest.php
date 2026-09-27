<?php

namespace Tests\Feature\Analytics;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class AnalyticsTest extends TestCase
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

    private function local(string $modifier): string
    {
        return now('Asia/Manila')->modify($modifier)->format('Y-m-d\TH:i');
    }

    /** A client inquiry sent directly to these organizations; returns the inquiry id. */
    private function inquiry(User $client, array $orgIds): string
    {
        $id = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(), 'guest_count' => 80, 'location_text' => 'Lanang, Davao City',
        ])->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$id}/submit", ['mode' => 'direct', 'organization_ids' => $orgIds], $this->key())->assertOk();

        return $id;
    }

    private function quote(User $owner, Organization $org, string $inquiry, int $amount): string
    {
        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$inquiry}/quotations",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => $amount]], 'submit' => true], $this->key())->assertCreated()->json('data.id');
    }

    public function test_bid_performance_counts_each_inquiry_once_by_its_outcome(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$rival, $rivalOwner] = $this->listedOrganizer();
        $client = User::factory()->create();

        $won = $this->inquiry($client, [$org->id]);
        $wonQuote = $this->quote($owner, $org, $won, 50000);
        $this->actingAs($client)->spaJson('POST', "/api/v1/quotations/{$wonQuote}/accept", [], $this->key())->assertOk();

        $lost = $this->inquiry($client, [$org->id, $rival->id]);
        $this->quote($owner, $org, $lost, 40000);
        $rivalQuote = $this->quote($rivalOwner, $rival, $lost, 35000);
        $this->actingAs($client)->spaJson('POST', "/api/v1/quotations/{$rivalQuote}/accept", [], $this->key())->assertOk();

        $pending = $this->inquiry($client, [$org->id]);
        $first = $this->quote($owner, $org, $pending, 30000);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/quotations/{$first}/revise",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 28000]]], $this->key())->assertSuccessful(); // not a second bid

        $declined = $this->inquiry($client, [$org->id]);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$declined}/decline", ['reason' => 'Fully booked'])->assertOk();

        $missed = $this->inquiry($client, [$org->id]);
        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$missed}/cancel", ['reason' => 'Changed plans'])->assertOk();

        $this->inquiry($client, [$org->id]); // still open, no answer yet

        $bids = $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics")->assertOk()->json('data.bids');
        $this->assertSame(['inquiries' => 6, 'open' => 1, 'bids' => 3, 'won' => 1, 'lost' => 1, 'pending' => 1, 'declined' => 1, 'missed' => 1],
            array_intersect_key($bids['totals'], array_flip(['inquiries', 'open', 'bids', 'won', 'lost', 'pending', 'declined', 'missed'])));
        $this->assertEquals(50, $bids['totals']['win_rate']);
        $this->assertEquals(50, $bids['totals']['response_rate']);
        $this->assertSame('50000.00', $bids['totals']['won_value']);
        $this->assertNotNull($bids['totals']['median_response_hours']);

        $month = collect($bids['trend'])->firstWhere('key', now('Asia/Manila')->format('Y-m'));
        $this->assertSame([6, 3, 1, 1], [$month['inquiries'], $month['bids'], $month['won'], $month['lost']]);
        $this->assertSame(['Wedding', 6, 3, 1], [$bids['by_event_type'][0]['event_type'], $bids['by_event_type'][0]['inquiries'], $bids['by_event_type'][0]['bids'], $bids['by_event_type'][0]['won']]);
        $this->assertCount(6, $bids['history']);
        $this->assertEqualsCanonicalizing(['won', 'lost', 'pending', 'declined', 'missed', 'open'], array_column($bids['history'], 'outcome'));

        // The rival sees only its own bids.
        $this->actingAs($rivalOwner)->spaJson('GET', "/api/v1/organizations/{$rival->id}/analytics")
            ->assertJsonPath('data.bids.totals.inquiries', 1)->assertJsonPath('data.bids.totals.won', 1);
    }

    public function test_event_performance_averages_attendance_and_groups_by_quarter(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Held Gala', 'visibility' => 'private', 'overall_capacity' => 10,
            'sessions' => [['starts_at' => $this->local('+1 hour'), 'ends_at' => $this->local('+4 hours')]],
        ], $this->key())->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $tier = collect($this->actingAs($owner)->spaJson('POST', "{$base}/ticket-types", ['name' => 'Guest', 'price' => 0, 'capacity' => 20])->json('data'))->firstWhere('name', 'Guest')['id'];
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();
        $tokens = collect(['Ana', 'Ben', 'Cara', 'Dan'])->map(fn ($n) => $this->actingAs($owner)->spaJson('POST', "{$base}/guests",
            ['full_name' => $n, 'ticket_type_id' => $tier, 'admission_type' => 'invited', 'send_email' => false], $this->key())->json('data.qr_token'));
        foreach ($tokens->take(3) as $t) {
            $this->actingAs($owner)->spaJson('POST', "{$base}/checkin/scan", ['token' => $t])->assertJsonPath('data.result', 'checked_in');
        }
        $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/headcount", ['count' => 1], $this->key())->assertCreated();

        // A second event later this week, not held yet.
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Coming Soon', 'sessions' => [['starts_at' => $this->local('+3 days'), 'ends_at' => $this->local('+3 days +2 hours')]],
        ], $this->key())->assertCreated();

        $this->travelTo(now()->addHours(2));
        $this->actingAs($owner)->spaJson('POST', "{$base}/complete")->assertOk();

        $events = $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics?range=3m")->assertOk()->json('data.events');
        $this->assertSame(1, $events['totals']['completed']);
        $this->assertEquals(75, $events['totals']['average_attendance_rate']);
        $this->assertEquals(25, $events['totals']['no_show_rate']);
        $this->assertEquals(25, $events['totals']['walk_in_share']);
        $this->assertEquals(40, $events['totals']['average_capacity_fill']);
        $this->assertSame(4, $events['totals']['attendees']);
        $this->assertSame('0.00', $events['totals']['revenue']);
        $held = collect($events['events'])->firstWhere('name', 'Held Gala');
        $this->assertSame([4, 3, 1, 1], [$held['expected'], $held['attended'], $held['no_shows'], $held['walk_ins']]);

        $q = $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics?range=12m&group=quarter")->assertOk()->json('data');
        $now = now('Asia/Manila');
        $this->assertSame('Q'.$now->quarter.' '.$now->year, end($q['period']['labels']));
        $this->assertSame(1, collect($q['events']['trend'])->firstWhere('key', $now->year.'-Q'.$now->quarter)['events']);
    }

    public function test_sections_follow_roles_and_live_shows_the_door(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Door Open', 'overall_capacity' => 10,
            'sessions' => [['starts_at' => $this->local('+1 hour'), 'ends_at' => $this->local('+4 hours')]],
        ], $this->key())->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $tier = collect($this->actingAs($owner)->spaJson('POST', "{$base}/ticket-types", ['name' => 'Guest', 'price' => 0, 'capacity' => 20])->json('data'))->firstWhere('name', 'Guest')['id'];
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();
        $token = $this->actingAs($owner)->spaJson('POST', "{$base}/guests", ['full_name' => 'Ana', 'ticket_type_id' => $tier, 'admission_type' => 'invited', 'send_email' => false], $this->key())->json('data.qr_token');
        $this->actingAs($owner)->spaJson('POST', "{$base}/guests", ['full_name' => 'Ben', 'ticket_type_id' => $tier, 'admission_type' => 'invited', 'send_email' => false], $this->key());
        $this->actingAs($owner)->spaJson('POST', "{$base}/checkin/scan", ['token' => $token])->assertJsonPath('data.result', 'checked_in');
        $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/headcount", ['count' => 2], $this->key())->assertCreated();
        $later = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Next Week', 'sessions' => [['starts_at' => $this->local('+3 days'), 'ends_at' => $this->local('+3 days +2 hours')]],
        ], $this->key())->json('data.id');
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$later}/publish")->assertOk();

        $live = $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics/live")->assertOk()->json('data');
        $this->assertCount(1, $live['live']);
        $e = $live['live'][0];
        $this->assertSame(['Door Open', 2, 1, 2, 3], [$e['name'], $e['expected'], $e['checked_in'], $e['walk_ins'], $e['arrived']]);
        $this->assertEquals(50, $e['check_in_rate']);
        $this->assertEquals(66.7, $e['walk_in_ratio']);
        $this->assertSame(3, $e['arrivals_last_15_min']);
        $this->assertSame(40, $e['capacity']['percent']); // booked: 2 tickets + 2 walk-ins of 10
        $this->assertEquals(30, $e['occupancy']);           // inside now: 1 checked in + 2 walk-ins
        $this->assertSame('Next Week', $live['upcoming'][0]['name']);

        // Door staff: live numbers for their event only; no business analytics.
        $door = $this->businessUser();
        $this->addStaff($org, $door);
        $this->assignToEvent($owner, $org, $event, $door, ['checkin.perform']);
        $this->actingAs($door)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics")->assertForbidden();
        $this->actingAs($door)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics/live")->assertOk()
            ->assertJsonCount(1, 'data.live')->assertJsonCount(0, 'data.upcoming');

        // Outsiders see nothing.
        [$other, $otherOwner] = $this->listedOrganizer();
        $this->actingAs($otherOwner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics")->assertNotFound();
        $this->actingAs($otherOwner)->spaJson('GET', "/api/v1/organizations/{$other->id}/analytics")->assertOk()->assertJsonPath('data.events.totals.events', 0);
    }
}
