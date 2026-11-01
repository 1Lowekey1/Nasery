<?php

namespace Tests\Feature\Events;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** The final agreed price (0055): organizer-only, editable after the event, and what won value counts. */
class AgreedAmountTest extends TestCase
{
    use InteractsWithOrganizations;

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    public function test_the_final_price_replaces_the_offer_in_won_value_and_stays_editable_after_the_event(): void
    {
        Mail::fake();
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();

        // A ₱50,000 offer the client accepts, turned into an event.
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
            'name' => 'Price Wedding', 'sessions' => [['starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->addHours(4)->format('Y-m-d\TH:i')]],
        ], $this->key())->assertCreated()->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $wonValue = fn () => $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics?range=3m")->json('data.bids.totals.won_value');

        $this->actingAs($owner)->spaJson('GET', $base)->assertOk()
            ->assertJsonPath('data.price.offer_amount', '50000.00')->assertJsonPath('data.price.agreed_amount', null)->assertJsonPath('data.can.set_price', true);
        $this->assertSame('50000.00', $wonValue());

        // Added services: ₱62,500 with a note; won value follows, the average bid doesn't.
        $this->actingAs($owner)->spaJson('PUT', "{$base}/agreed-amount", ['agreed_amount' => 62500, 'agreed_amount_note' => 'Added photo booth'])
            ->assertOk()->assertJsonPath('data.price.agreed_amount', '62500.00')->assertJsonPath('data.price.agreed_amount_note', 'Added photo booth');
        $this->assertSame('62500.00', $wonValue());
        $this->assertTrue(DB::table('audit_logs')->where('entity_id', $event)->where('action', 'event.agreed_amount_set')->exists());

        // After the event (final accounting) it can still change; the client never sees it.
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();
        $this->travelTo(now()->addHours(2));
        $this->actingAs($owner)->spaJson('POST', "{$base}/complete")->assertOk();
        $this->actingAs($owner)->spaJson('PUT', "{$base}/agreed-amount", ['agreed_amount' => 60000, 'agreed_amount_note' => 'Final: less 4% for the delay'])->assertOk();
        $this->assertSame('60000.00', $wonValue());
        $this->assertArrayNotHasKey('price', $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}")->assertOk()->json('data'));

        // Clearing it goes back to the offer; bad amounts are refused.
        $this->actingAs($owner)->spaJson('PUT', "{$base}/agreed-amount", ['agreed_amount' => -5])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('PUT', "{$base}/agreed-amount", ['agreed_amount' => null])->assertOk()->assertJsonPath('data.price.agreed_amount', null);
        $this->assertSame('50000.00', $wonValue());
    }
}
