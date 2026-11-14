<?php

namespace Tests\Feature\Inquiries;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class BookingCancellationTest extends TestCase
{
    use InteractsWithOrganizations;

    private User $client;

    private Organization $org;

    private User $owner;

    private string $inquiry;

    private string $quotation;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->client = User::factory()->create();
        [$this->org, $this->owner] = $this->listedOrganizer();

        $this->inquiry = $this->actingAs($this->client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(), 'guest_count' => 90, 'location_text' => 'Davao City',
        ])->json('data.id');
        $this->actingAs($this->client)->spaJson('POST', "/api/v1/inquiries/{$this->inquiry}/submit", ['mode' => 'direct', 'organization_ids' => [$this->org->id]], $this->key())->assertOk();
        $this->quotation = $this->actingAs($this->owner)->spaJson('POST', "/api/v1/organizations/{$this->org->id}/inquiries/{$this->inquiry}/quotations",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 50000]], 'submit' => true], $this->key())->assertCreated()->json('data.id');
        $this->actingAs($this->client)->spaJson('POST', "/api/v1/quotations/{$this->quotation}/accept", [], $this->key())->assertOk();
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function asClient(string $method, string $path = '', array $body = [])
    {
        return $this->actingAs($this->client)->spaJson($method, "/api/v1/inquiries/{$this->inquiry}/cancellation{$path}", $body);
    }

    private function asOwner(string $method, string $path = '', array $body = [])
    {
        return $this->actingAs($this->owner)->spaJson($method, "/api/v1/organizations/{$this->org->id}/inquiries/{$this->inquiry}/cancellation{$path}", $body);
    }

    public function test_both_sides_agree_and_the_booking_is_cancelled(): void
    {
        $this->asClient('GET')->assertOk()->assertJsonPath('data.can_request', true)->assertJsonPath('data.cancellation', null);
        $this->asClient('POST', '', ['reason' => 'x'])->assertUnprocessable();
        $this->asClient('POST', '', ['reason' => 'Our venue fell through'])->assertOk()
            ->assertJsonPath('data.cancellation.status', 'pending')->assertJsonPath('data.cancellation.can_withdraw', true)
            ->assertJsonPath('data.can_request', false);

        // One request at a time, and the asker can't answer their own request.
        $this->asClient('POST', '', ['reason' => 'Asking again'])->assertConflict();
        $this->asClient('POST', '/respond', ['decision' => 'agree'])->assertForbidden();

        $this->asOwner('GET')->assertJsonPath('data.cancellation.can_respond', true)->assertJsonPath('data.cancellation.reason', 'Our venue fell through');
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $this->owner->id, 'type' => 'booking.cancellation']);

        $this->asOwner('POST', '/respond', ['decision' => 'agree'])->assertOk()->assertJsonPath('data.cancellation.status', 'agreed')->assertJsonPath('data.can_request', false);
        $this->assertDatabaseHas('inquiries', ['id' => $this->inquiry, 'status' => 'cancelled']);
        $this->assertDatabaseHas('quotations', ['id' => $this->quotation, 'status' => 'cancelled']);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $this->client->id, 'type' => 'booking.cancellation']);
    }

    public function test_declining_keeps_the_booking_and_it_can_be_asked_again(): void
    {
        $this->asOwner('POST', '', ['reason' => 'Double booked by mistake'])->assertOk();
        $this->asOwner('POST', '/respond', ['decision' => 'agree'])->assertForbidden();
        $this->asClient('POST', '/respond', ['decision' => 'decline', 'note' => 'Please keep it'])->assertOk()
            ->assertJsonPath('data.cancellation.status', 'declined')->assertJsonPath('data.can_request', true);
        $this->assertDatabaseHas('inquiries', ['id' => $this->inquiry, 'status' => 'accepted']);

        $this->asOwner('POST', '', ['reason' => 'Still double booked'])->assertOk();
        $this->asOwner('DELETE')->assertOk()->assertJsonPath('data.cancellation.status', 'withdrawn');
        $this->assertDatabaseHas('inquiries', ['id' => $this->inquiry, 'status' => 'accepted']);
    }

    public function test_no_reply_in_time_cancels_the_booking_and_its_event(): void
    {
        $event = $this->actingAs($this->owner)->spaJson('POST', "/api/v1/organizations/{$this->org->id}/quotations/{$this->quotation}/event", [
            'name' => 'Reyes Wedding', 'sessions' => [['starts_at' => now('Asia/Manila')->addMonth()->format('Y-m-d\TH:i'), 'ends_at' => now('Asia/Manila')->addMonth()->addHours(4)->format('Y-m-d\TH:i')]],
        ], $this->key())->assertCreated()->json('data.id');

        $this->asClient('POST', '', ['reason' => 'Our plans changed'])->assertOk();

        $this->artisan('nasery:bookings:expire')->assertSuccessful();
        $this->assertDatabaseHas('booking_cancellations', ['inquiry_id' => $this->inquiry, 'status' => 'pending']);

        DB::table('booking_cancellations')->where('inquiry_id', $this->inquiry)->update(['respond_by' => now()->subMinute()]);
        $this->artisan('nasery:bookings:expire')->assertSuccessful();

        $this->assertDatabaseHas('booking_cancellations', ['inquiry_id' => $this->inquiry, 'status' => 'auto_agreed']);
        $this->assertDatabaseHas('events', ['id' => $event, 'status' => 'cancelled']);
        $this->assertDatabaseHas('inquiries', ['id' => $this->inquiry, 'status' => 'cancelled']);
    }

    public function test_a_started_event_cant_be_cancelled_this_way_and_outsiders_are_kept_out(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)->spaJson('GET', "/api/v1/inquiries/{$this->inquiry}/cancellation")->assertNotFound();
        [$rival, $rivalOwner] = $this->listedOrganizer('Rival Co');
        $this->actingAs($rivalOwner)->spaJson('GET', "/api/v1/organizations/{$rival->id}/inquiries/{$this->inquiry}/cancellation")->assertNotFound();

        $event = $this->actingAs($this->owner)->spaJson('POST', "/api/v1/organizations/{$this->org->id}/quotations/{$this->quotation}/event", [
            'name' => 'Reyes Wedding', 'sessions' => [['starts_at' => now('Asia/Manila')->addMonth()->format('Y-m-d\TH:i'), 'ends_at' => now('Asia/Manila')->addMonth()->addHours(4)->format('Y-m-d\TH:i')]],
        ], $this->key())->assertCreated()->json('data.id');
        DB::table('events')->where('id', $event)->update(['status' => 'ongoing']);

        $this->asClient('GET')->assertJsonPath('data.can_request', false);
        $this->asClient('POST', '', ['reason' => 'Too late now'])->assertConflict();
    }
}
