<?php

namespace Tests\Feature\CheckIn;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class WalkInTest extends TestCase
{
    use InteractsWithOrganizations;

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function local(string $modifier): string
    {
        return now('Asia/Manila')->modify($modifier)->format('Y-m-d\TH:i');
    }

    /** A published event whose door is open now; returns [eventId, base walk-in url]. */
    private function event(User $owner, Organization $org, ?int $capacity, bool $publish = true, string $start = '+1 hour'): array
    {
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Open Air Fair', 'visibility' => 'public', 'overall_capacity' => $capacity,
            'sessions' => [['starts_at' => $this->local($start), 'ends_at' => $this->local("{$start} +5 hours")]],
        ], $this->key())->assertCreated()->json('data.id');
        if ($publish) {
            $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();
        }

        return [$event, "/api/v1/organizations/{$org->id}/events/{$event}/walk-ins"];
    }

    private function tier(User $owner, Organization $org, string $event, array $data): string
    {
        return collect($this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/ticket-types", $data)->assertCreated()->json('data'))
            ->firstWhere('name', $data['name'])['id'];
    }

    public function test_a_door_sale_issues_a_checked_in_ticket_within_tier_capacity(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->event($owner, $org, 10);
        // A door-only tier kept off online sale still sells at the counter.
        $door = $this->tier($owner, $org, $event, ['name' => 'Door', 'price' => 500, 'capacity' => 2, 'status' => 'draft']);

        $this->actingAs($owner)->spaJson('POST', "{$base}/sale", ['ticket_type_id' => $door, 'full_name' => 'Rico Dizon'], $this->key())
            ->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['cash_received']]]]);

        $sale = $this->actingAs($owner)->spaJson('POST', "{$base}/sale",
            ['ticket_type_id' => $door, 'full_name' => 'Rico Dizon', 'contact_number' => '0917 555 0101', 'cash_received' => true], $this->key())
            ->assertCreated()->assertJsonPath('data.amount_due', '500.00')->assertJsonPath('data.over_capacity_by', 0)->json('data');

        $ticket = DB::table('tickets')->where('id', $sale['ticket_id'])->first();
        $this->assertSame(['used', '500.00'], [$ticket->status, $ticket->purchased_price]);
        $this->assertSame('walk_in', DB::table('registrations')->where('id', $ticket->registration_id)->value('registration_source'));
        $this->assertSame(1, DB::table('attendance_records')->where('ticket_id', $ticket->id)->count());
        $this->assertSame('ticket_linked', DB::table('walk_in_records')->where('id', $sale['walk_in_id'])->value('category'));

        $this->actingAs($owner)->spaJson('POST', "{$base}/sale", ['ticket_type_id' => $door, 'full_name' => 'Mia Dizon', 'cash_received' => true], $this->key())->assertCreated();

        // The tier is full: a hard stop, even for someone allowed to override the venue limit.
        $this->actingAs($owner)->spaJson('POST', "{$base}/sale",
            ['ticket_type_id' => $door, 'full_name' => 'Late Guest', 'cash_received' => true, 'override_reason' => 'VIP friend'], $this->key())
            ->assertStatus(409)->assertJsonPath('error.code', 'CAPACITY_EXCEEDED')->assertJsonPath('error.details.scope', 'tier');

        $this->actingAs($owner)->spaJson('GET', $base)->assertOk()
            ->assertJsonPath('data.totals.door_sales', 2)->assertJsonPath('data.totals.door_revenue', '1000.00')
            ->assertJsonPath('data.tiers.0.remaining', 0)
            ->assertJsonPath('data.capacity.used', 2)->assertJsonPath('data.capacity.level', 'ok');
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}/checkin")
            ->assertJsonPath('data.checked_in', 2)->assertJsonPath('data.walk_ins', 0)->assertJsonPath('data.arrived', 2);
    }

    public function test_names_and_headcount_fill_the_venue_and_a_manager_can_override(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->event($owner, $org, 10);
        $door = $this->businessUser();
        $this->addStaff($org, $door);
        $this->assignToEvent($owner, $org, $event, $door, ['checkin.perform']);

        $this->actingAs($door)->spaJson('POST', "{$base}/name", ['full_name' => 'Joy Santos', 'email' => 'Joy@Example.com'], $this->key())->assertCreated();
        $this->assertSame('registered_attendee', DB::table('walk_in_records')->where('event_id', $event)->value('category'));
        $this->actingAs($door)->spaJson('POST', "{$base}/headcount", ['count' => 8], $this->key())->assertCreated();
        $this->actingAs($door)->spaJson('GET', $base)->assertJsonPath('data.capacity.level', 'warning')->assertJsonPath('data.capacity.percent', 90)
            ->assertJsonPath('data.can', ['record' => true, 'override' => false, 'undo' => false]);
        $this->actingAs($door)->spaJson('POST', "{$base}/headcount", ['count' => 1], $this->key())->assertCreated();
        $this->actingAs($door)->spaJson('GET', $base)->assertJsonPath('data.capacity.level', 'full')->assertJsonPath('data.capacity.remaining', 0);

        // Full: door staff are stopped and can't override.
        $this->actingAs($door)->spaJson('POST', "{$base}/headcount", ['count' => 2], $this->key())
            ->assertStatus(409)->assertJsonPath('error.details.scope', 'event');
        $this->actingAs($door)->spaJson('POST', "{$base}/headcount", ['count' => 2, 'override_reason' => 'Band members'], $this->key())->assertForbidden();

        // Someone who can edit the event admits past capacity with a reason.
        $this->actingAs($owner)->spaJson('POST', "{$base}/headcount", ['count' => 2, 'override_reason' => 'Band members'], $this->key())
            ->assertCreated()->assertJsonPath('data.over_capacity_by', 2);
        $this->actingAs($owner)->spaJson('GET', $base)->assertJsonPath('data.capacity.level', 'over')->assertJsonPath('data.capacity.used', 12)
            ->assertJsonPath('data.totals.names', 1)->assertJsonPath('data.totals.headcount', 11);
        $this->assertDatabaseHas('audit_logs', ['action' => 'walkin.capacity_override', 'reason' => 'Band members']);
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}/checkin")->assertJsonPath('data.walk_ins', 12)->assertJsonPath('data.arrived', 12);
    }

    public function test_a_double_tap_counts_once_and_mistakes_can_be_undone(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->event($owner, $org, 3);
        $tier = $this->tier($owner, $org, $event, ['name' => 'Free entry', 'price' => 0, 'capacity' => 3]);

        $key = $this->key();
        $this->actingAs($owner)->spaJson('POST', "{$base}/headcount", ['count' => 1], $key)->assertCreated();
        $this->actingAs($owner)->spaJson('POST', "{$base}/headcount", ['count' => 1], $key)->assertCreated();
        $this->assertSame(1, (int) DB::table('walk_in_records')->where('event_id', $event)->sum('count'));

        $sale = $this->actingAs($owner)->spaJson('POST', "{$base}/sale", ['ticket_type_id' => $tier, 'full_name' => 'Wrong Person'], $this->key())
            ->assertCreated()->assertJsonPath('data.amount_due', '0.00')->json('data');

        $undo = "{$base}/{$sale['walk_in_id']}/undo";
        $door = $this->businessUser();
        $this->addStaff($org, $door);
        $this->assignToEvent($owner, $org, $event, $door, ['checkin.perform']);
        $this->actingAs($door)->spaJson('POST', $undo, ['reason' => 'Typo'])->assertForbidden();

        $this->actingAs($owner)->spaJson('POST', $undo, ['reason' => 'Registered the wrong person'])->assertOk();
        $this->assertDatabaseMissing('walk_in_records', ['id' => $sale['walk_in_id']]);
        $this->assertSame('cancelled', DB::table('tickets')->where('id', $sale['ticket_id'])->value('status'));
        $this->assertSame(0, DB::table('attendance_records')->where('ticket_id', $sale['ticket_id'])->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'walkin.undone', 'entity_id' => $sale['walk_in_id']]);
        $this->actingAs($owner)->spaJson('GET', $base)->assertJsonPath('data.capacity.used', 1);
    }

    public function test_the_door_must_be_open(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [, $draft] = $this->event($owner, $org, null, publish: false);
        $this->actingAs($owner)->spaJson('POST', "{$draft}/headcount", ['count' => 1], $this->key())
            ->assertStatus(409)->assertJsonPath('error.message', 'Walk-ins can be recorded once the event is published.');

        [, $later] = $this->event($owner, $org, null, start: '+3 days');
        $this->actingAs($owner)->spaJson('POST', "{$later}/name", ['full_name' => 'Too Early'], $this->key())
            ->assertStatus(409)->assertJsonPath('error.message', fn ($m) => str_starts_with($m, 'Check-in opens '));

        // No overall capacity set: walk-ins are counted, never refused.
        [, $open] = $this->event($owner, $org, null);
        $this->actingAs($owner)->spaJson('POST', "{$open}/headcount", ['count' => 50], $this->key())->assertCreated();
        $this->actingAs($owner)->spaJson('GET', $open)->assertJsonPath('data.capacity.level', 'unlimited')->assertJsonPath('data.capacity.used', 50);
    }
}
