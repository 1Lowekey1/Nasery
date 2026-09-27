<?php

namespace Tests\Feature\CheckIn;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class CheckInTest extends TestCase
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

    /** A published event with the given sessions and a free "Guest" tier; returns [eventId, tierId]. */
    private function event(User $owner, Organization $org, ?array $sessions = null, bool $publish = true): array
    {
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Door Test', 'visibility' => 'private',
            'sessions' => $sessions ?? [['starts_at' => $this->local('+1 hour'), 'ends_at' => $this->local('+5 hours')]],
        ], $this->key())->assertCreated()->json('data.id');
        $tier = collect($this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/ticket-types",
            ['name' => 'Guest', 'zone_name' => 'Hall A', 'benefits' => ['Dinner'], 'price' => 0, 'capacity' => 50])->assertCreated()->json('data'))->firstWhere('name', 'Guest')['id'];
        if ($publish) {
            $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();
        }

        return [$event, $tier];
    }

    /** @return array{ticket_id: string, qr_token: string} */
    private function guest(User $owner, Organization $org, string $event, string $tier, string $name = 'Ana Cruz'): array
    {
        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/guests",
            ['full_name' => $name, 'email' => Str::slug($name).'@example.com', 'ticket_type_id' => $tier, 'admission_type' => 'invited'], $this->key())
            ->assertCreated()->json('data');
    }

    private function scan(User $by, Organization $org, string $event, string $token, array $extra = [])
    {
        return $this->actingAs($by)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/checkin/scan", ['token' => $token, ...$extra]);
    }

    public function test_a_scan_admits_once_and_flags_duplicates_and_bad_codes(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$event, $tier] = $this->event($owner, $org);
        $ana = $this->guest($owner, $org, $event, $tier);

        $this->scan($owner, $org, $event, $ana['qr_token'])->assertOk()
            ->assertJsonPath('data.result', 'checked_in')
            ->assertJsonPath('data.guest.name', 'Ana Cruz')
            ->assertJsonPath('data.guest.zone', 'Hall A')
            ->assertJsonPath('data.guest.benefits', ['Dinner']);
        $this->assertSame('used', DB::table('tickets')->where('id', $ana['ticket_id'])->value('status'));
        $this->assertSame('qr_scan', DB::table('attendance_records')->where('ticket_id', $ana['ticket_id'])->value('method'));

        // A second scan (another door, or the same guest again) is flagged, not admitted twice.
        $this->scan($owner, $org, $event, $ana['qr_token'])->assertOk()->assertJsonPath('data.result', 'duplicate')->assertJsonPath('data.duplicate_attempts', 1);
        $this->scan($owner, $org, $event, " {$ana['qr_token']} ")->assertJsonPath('data.result', 'duplicate')->assertJsonPath('data.duplicate_attempts', 2);
        $this->assertSame(1, DB::table('attendance_records')->where('ticket_id', $ana['ticket_id'])->count());

        // Garbage, a forged token and another event's ticket.
        $this->scan($owner, $org, $event, 'https://example.com/free-entry')->assertJsonPath('data.result', 'invalid');
        $this->scan($owner, $org, $event, 'NSR1.'.Str::uuid().'.'.str_repeat('A', 43))->assertJsonPath('data.result', 'invalid');
        [$other, $otherTier] = $this->event($owner, $org);
        $ben = $this->guest($owner, $org, $other, $otherTier, 'Ben Lim');
        $this->scan($owner, $org, $event, $ben['qr_token'])->assertJsonPath('data.result', 'wrong_event')->assertJsonPath('data.guest', null);

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}/checkin")->assertOk()
            ->assertJsonPath('data.expected', 1)->assertJsonPath('data.checked_in', 1)
            ->assertJsonPath('data.sessions.0.is_open', true)->assertJsonPath('data.sessions.0.checked_in', 1)
            ->assertJsonPath('data.tiers.0.checked_in', 1)
            ->assertJsonPath('data.recent.0.name', 'Ana Cruz')
            ->assertJsonPath('data.recent.0.duplicate_attempts', 2)
            ->assertJsonPath('data.can', ['scan' => true, 'undo' => true]);
    }

    public function test_replaced_cancelled_and_unpaid_tickets_are_refused(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$event, $tier] = $this->event($owner, $org);
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";

        $ana = $this->guest($owner, $org, $event, $tier);
        $fresh = $this->actingAs($owner)->spaJson('POST', "{$base}/tickets/{$ana['ticket_id']}/reissue-qr")->assertOk()->json('data.qr_token');
        $this->scan($owner, $org, $event, $ana['qr_token'])->assertJsonPath('data.result', 'invalid');
        $this->scan($owner, $org, $event, $fresh)->assertJsonPath('data.result', 'checked_in');

        $ben = $this->guest($owner, $org, $event, $tier, 'Ben Lim');
        $this->actingAs($owner)->spaJson('POST', "{$base}/tickets/{$ben['ticket_id']}/cancel", ['reason' => 'Declined the invitation'])->assertOk();
        $this->scan($owner, $org, $event, $ben['qr_token'])->assertJsonPath('data.result', 'cancelled')->assertJsonPath('data.guest.name', 'Ben Lim');

        // A reserved-but-unpaid ticket can't be let in manually either.
        $cara = $this->guest($owner, $org, $event, $tier, 'Cara Tan');
        DB::table('tickets')->where('id', $cara['ticket_id'])->update(['status' => 'pending']);
        $this->actingAs($owner)->spaJson('POST', "{$base}/checkin/manual", ['ticket_id' => $cara['ticket_id']])->assertJsonPath('data.result', 'unpaid');
    }

    public function test_check_in_follows_the_session_window_and_event_state(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$later, $tier] = $this->event($owner, $org, [['starts_at' => $this->local('+5 days 18:00'), 'ends_at' => $this->local('+5 days 22:00')]]);
        $ana = $this->guest($owner, $org, $later, $tier);

        $this->scan($owner, $org, $later, $ana['qr_token'])->assertJsonPath('data.result', 'not_open')
            ->assertJsonPath('data.message', fn ($m) => str_starts_with($m, 'Check-in opens '));

        $this->travelTo(now('Asia/Manila')->modify('+5 days 15:30')); // 2.5 hours before doors: open
        $this->scan($owner, $org, $later, $ana['qr_token'])->assertJsonPath('data.result', 'checked_in');

        $this->travelTo(now('Asia/Manila')->modify('+1 day'));
        $ben = $this->guest($owner, $org, $later, $tier, 'Ben Lim');
        $this->scan($owner, $org, $later, $ben['qr_token'])->assertJsonPath('data.result', 'not_open')
            ->assertJsonPath('data.message', 'Check-in has closed: every session of this event has ended.');
        $this->travelBack();

        [$draft, $draftTier] = $this->event($owner, $org, publish: false);
        $cara = $this->guest($owner, $org, $draft, $draftTier, 'Cara Tan');
        $this->scan($owner, $org, $draft, $cara['qr_token'])->assertJsonPath('data.result', 'event_closed');
    }

    public function test_a_multi_session_ticket_is_admitted_once_per_session(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$event, $tier] = $this->event($owner, $org, [
            ['name' => 'Day 1', 'starts_at' => $this->local('+1 hour'), 'ends_at' => $this->local('+4 hours')],
            ['name' => 'Day 2', 'starts_at' => $this->local('+1 day +1 hour'), 'ends_at' => $this->local('+1 day +4 hours')],
        ]);
        $ana = $this->guest($owner, $org, $event, $tier);

        $this->scan($owner, $org, $event, $ana['qr_token'])->assertJsonPath('data.result', 'checked_in')->assertJsonPath('data.session.label', 'Day 1');
        $day2 = DB::table('event_sessions')->where('event_id', $event)->where('name', 'Day 2')->value('id');
        $this->scan($owner, $org, $event, $ana['qr_token'], ['session_id' => $day2])->assertJsonPath('data.result', 'not_open');

        $this->travelTo(now()->addDay());
        $this->scan($owner, $org, $event, $ana['qr_token'])->assertJsonPath('data.result', 'checked_in')->assertJsonPath('data.session.label', 'Day 2');
        $this->scan($owner, $org, $event, $ana['qr_token'])->assertJsonPath('data.result', 'duplicate');
        $this->assertSame(2, DB::table('attendance_records')->where('ticket_id', $ana['ticket_id'])->count());
    }

    public function test_manual_check_in_undo_and_door_staff_permissions(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$event, $tier] = $this->event($owner, $org);
        $base = "/api/v1/organizations/{$org->id}/events/{$event}/checkin";
        $ana = $this->guest($owner, $org, $event, $tier);

        $door = $this->businessUser();
        $this->addStaff($org, $door);
        $this->assignToEvent($owner, $org, $event, $door, ['checkin.perform']);
        $outsider = $this->businessUser();
        $this->addStaff($org, $outsider);

        $this->actingAs($door)->spaJson('GET', "{$base}/search?q=ana")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ana Cruz');
        $id = $this->actingAs($door)->spaJson('POST', "{$base}/manual", ['ticket_id' => $ana['ticket_id']])->assertOk()
            ->assertJsonPath('data.result', 'checked_in')->json('data.attendance_id');
        $this->assertSame('manual', DB::table('attendance_records')->where('id', $id)->value('method'));
        $this->actingAs($door)->spaJson('GET', $base)->assertOk()->assertJsonPath('data.can', ['scan' => true, 'undo' => false])
            ->assertJsonPath('data.recent.0.method', 'manual');

        // Door staff can't undo; unassigned staff can't even see the event.
        $this->actingAs($door)->spaJson('POST', "{$base}/{$id}/undo", ['reason' => 'Wrong guest'])->assertForbidden();
        $this->actingAs($outsider)->spaJson('POST', "{$base}/scan", ['token' => $ana['qr_token']])->assertNotFound();

        $this->actingAs($owner)->spaJson('POST', "{$base}/{$id}/undo", ['reason' => 'x'])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$id}/undo", ['reason' => 'Checked in the wrong Ana'])->assertOk();
        $this->assertSame('valid', DB::table('tickets')->where('id', $ana['ticket_id'])->value('status'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'checkin.undone', 'entity_id' => $ana['ticket_id'], 'reason' => 'Checked in the wrong Ana']);
        $this->scan($door, $org, $event, $ana['qr_token'])->assertJsonPath('data.result', 'checked_in');
    }

    public function test_the_client_sees_live_numbers_for_their_own_event_only(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        $inquiry = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(), 'guest_count' => 90, 'location_text' => 'Lanang, Davao City',
        ])->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$inquiry}/submit", ['mode' => 'direct', 'organization_ids' => [$org->id]], $this->key())->assertOk();
        $quotation = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$inquiry}/quotations",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 50000]], 'submit' => true], $this->key())->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/quotations/{$quotation}/accept", [], $this->key())->assertOk();
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/quotations/{$quotation}/event", [
            'name' => 'Reyes Wedding', 'sessions' => [['starts_at' => $this->local('+1 hour'), 'ends_at' => $this->local('+6 hours')]],
        ], $this->key())->assertCreated()->json('data.id');
        $tier = collect($this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/ticket-types",
            ['name' => 'Guest', 'price' => 0, 'capacity' => 100])->json('data'))->firstWhere('name', 'Guest')['id'];
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();
        $ana = $this->guest($owner, $org, $event, $tier);
        $this->guest($owner, $org, $event, $tier, 'Ben Lim');
        $this->scan($owner, $org, $event, $ana['qr_token'])->assertJsonPath('data.result', 'checked_in');

        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/attendance")->assertOk()
            ->assertJsonPath('data.expected', 2)->assertJsonPath('data.checked_in', 1)
            ->assertJsonPath('data.sessions.0.checked_in', 1)
            ->assertJsonMissingPath('data.recent');
        $this->actingAs(User::factory()->create())->spaJson('GET', "/api/v1/events/{$event}/attendance")->assertNotFound();
    }
}
