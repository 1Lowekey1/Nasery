<?php

namespace Tests\Feature\Reports;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ReportTest extends TestCase
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

    private function tier(User $owner, string $base, string $name, int $sort = 0): string
    {
        return collect($this->actingAs($owner)->spaJson('POST', "{$base}/ticket-types", ['name' => $name, 'price' => 0, 'capacity' => 50, 'sort_order' => $sort])
            ->assertCreated()->json('data'))->firstWhere('name', $name)['id'];
    }

    /** @return array{ticket_id: string, qr_token: string} */
    private function guest(User $owner, string $base, string $tier, string $name): array
    {
        return $this->actingAs($owner)->spaJson('POST', "{$base}/guests",
            ['full_name' => $name, 'ticket_type_id' => $tier, 'admission_type' => 'invited', 'send_email' => false], $this->key())->assertCreated()->json('data');
    }

    /** An inquiry from $client accepted for $org and converted into an event; returns [eventId, base]. */
    private function clientEvent(User $client, Organization $org, User $owner, array $event = []): array
    {
        $inquiry = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(), 'guest_count' => 90, 'location_text' => 'Lanang, Davao City',
        ])->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$inquiry}/submit", ['mode' => 'direct', 'organization_ids' => [$org->id]], $this->key())->assertOk();
        $quotation = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$inquiry}/quotations",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 50000]], 'submit' => true], $this->key())->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/quotations/{$quotation}/accept", [], $this->key())->assertOk();
        $id = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/quotations/{$quotation}/event", array_replace([
            'name' => 'Reyes Wedding', 'sessions' => [['starts_at' => $this->local('+1 hour'), 'ends_at' => $this->local('+5 hours')]],
        ], $event), $this->key())->assertCreated()->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$id}";
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();

        return [$id, $base];
    }

    public function test_the_report_counts_attendance_no_shows_walk_ins_and_arrivals(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Garden Gala', 'visibility' => 'private', 'overall_capacity' => 20,
            'sessions' => [['starts_at' => $this->local('+1 hour'), 'ends_at' => $this->local('+5 hours')]],
        ], $this->key())->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $guestTier = $this->tier($owner, $base, 'Guest');
        $vip = $this->tier($owner, $base, 'VIP', 1);
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();

        [$ana, $ben, $cara] = [$this->guest($owner, $base, $guestTier, 'Ana'), $this->guest($owner, $base, $guestTier, 'Ben'), $this->guest($owner, $base, $guestTier, 'Cara')];
        $dan = $this->guest($owner, $base, $vip, 'Dan');
        $this->guest($owner, $base, $vip, 'Eve');
        $this->actingAs($owner)->spaJson('POST', "{$base}/tickets/{$cara['ticket_id']}/cancel", ['reason' => 'Declined'])->assertOk();
        foreach ([$ana, $dan] as $g) {
            $this->actingAs($owner)->spaJson('POST', "{$base}/checkin/scan", ['token' => $g['qr_token']])->assertJsonPath('data.result', 'checked_in');
        }
        $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/sale", ['ticket_type_id' => $guestTier, 'full_name' => 'Fe'], $this->key())->assertCreated();
        $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/name", ['full_name' => 'Gino'], $this->key())->assertCreated();
        $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/headcount", ['count' => 3], $this->key())->assertCreated();

        $r = $this->actingAs($owner)->spaJson('GET', "{$base}/report")->assertOk()->json('data');
        $this->assertSame([
            'expected' => 5, 'attended' => 3, 'no_shows' => 2, 'attendance_rate' => 60, 'walk_ins' => 4, 'door_sales' => 1, 'people' => 7,
            'capacity' => 20, 'capacity_used' => 35, 'cancelled_tickets' => 1, 'manual_check_ins' => 1, 'repeat_scans' => 0,
        ], $r['totals']);
        $this->assertSame([['Guest', 3, 2, 1], ['VIP', 2, 1, 1]], array_map(fn ($t) => [$t['name'], $t['expected'], $t['attended'], $t['no_shows']], $r['tiers']));
        $this->assertEqualsCanonicalizing([['organizer', 4, 2], ['walk_in', 1, 1]], array_map(fn ($s) => [$s['source'], $s['expected'], $s['attended']], $r['sources']));
        $this->assertSame(7, collect($r['timeline']['buckets'])->sum(fn ($b) => $b['check_ins'] + $b['walk_ins']));
        $this->assertSame(15, $r['timeline']['bucket_minutes']);
        $this->assertSame('no_show', collect($r['attendees'])->firstWhere('name', 'Ben')['status']);
        $this->assertSame('cancelled', collect($r['attendees'])->firstWhere('name', 'Cara')['status']);
        // First in at the top, then no-shows, then cancelled (adviser, 2026-11).
        $this->assertSame(['Ana', 'Dan', 'Fe', 'Ben', 'Eve', 'Cara'], array_column($r['attendees'], 'name'));
        $this->assertSame([['name' => 'Gino']], array_map(fn ($w) => ['name' => $w['name']], $r['walk_in_names']));
        $this->assertSame('0.00', $r['revenue']['total']); // the owner has finance.view
        $this->assertFalse($r['final']);
        $this->assertFalse($r['can']['has_client']);

        $pdf = $this->actingAs($owner)->spaJson('GET', "{$base}/report/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $csv = $this->actingAs($owner)->spaJson('GET', "{$base}/report/csv")->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(1 + 6 + 1 + 1, $lines); // header, 6 tickets (incl. cancelled), Gino, headcount
        $this->assertTrue(collect($lines)->contains(fn ($l) => str_starts_with($l, 'Ben,') && str_contains($l, 'No-show')));

        // Door staff can scan but not read reports; independent events can't be shared.
        $door = $this->businessUser();
        $this->addStaff($org, $door);
        $this->assignToEvent($owner, $org, $event, $door, ['checkin.perform']);
        $this->actingAs($door)->spaJson('GET', "{$base}/report")->assertForbidden();
    }

    public function test_the_organizer_shares_frozen_versions_with_the_client(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->clientEvent($client, $org, $owner);
        $tier = $this->tier($owner, $base, 'Guest');
        $ana = $this->guest($owner, $base, $tier, 'Ana');
        $this->guest($owner, $base, $tier, 'Ben');
        $attendance = $this->actingAs($owner)->spaJson('POST', "{$base}/checkin/scan", ['token' => $ana['qr_token']])->json('data.attendance_id');

        $staff = $this->businessUser(); // guest-list staff (assigned while the event is running)
        $this->addStaff($org, $staff);
        $this->assignToEvent($owner, $org, $event, $staff, ['attendee.manage']);

        $this->actingAs($owner)->spaJson('POST', "{$base}/report/share", [], $this->key())->assertConflict(); // not completed yet
        $this->travelTo(now()->addHours(2));
        $this->actingAs($owner)->spaJson('POST', "{$base}/complete")->assertOk();

        // Guest-list staff may read but not share.
        $this->actingAs($staff)->spaJson('GET', "{$base}/report")->assertOk()->assertJsonPath('data.can.share', false)->assertJsonMissingPath('data.revenue');
        $this->actingAs($staff)->spaJson('POST', "{$base}/report/share", [], $this->key())->assertForbidden();

        $this->actingAs($owner)->spaJson('POST', "{$base}/report/share", ['note' => 'Thank you! 1 of 2 guests came.'], $this->key())
            ->assertCreated()->assertJsonPath('data.version', 1)->assertJsonPath('data.includes_revenue', false);

        $shared = $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/report")->assertOk()->json('data');
        $this->assertSame(1, $shared['shared']['version']);
        $this->assertSame('Thank you! 1 of 2 guests came.', $shared['shared']['note']);
        $this->assertSame(1, $shared['shared']['report']['totals']['attended']);
        $this->assertTrue($shared['shared']['report']['final']);
        $this->assertArrayNotHasKey('revenue', $shared['shared']['report']);
        $this->assertCount(2, $shared['shared']['report']['attendees']); // private event: the client's own guests
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $client->id)->where('type', 'report.shared')->count());

        // A later correction changes the live report, not what the client was sent.
        $this->actingAs($owner)->spaJson('POST', "{$base}/checkin/{$attendance}/undo", ['reason' => 'Scanned by mistake'])->assertOk();
        $this->actingAs($owner)->spaJson('GET', "{$base}/report")->assertJsonPath('data.totals.attended', 0);
        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/report")->assertJsonPath('data.shared.report.totals.attended', 1);

        // Version 2 with revenue; version 1 is still available unchanged.
        $this->actingAs($owner)->spaJson('POST', "{$base}/report/share", ['include_revenue' => true], $this->key())->assertJsonPath('data.version', 2);
        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/report")
            ->assertJsonPath('data.shared.version', 2)->assertJsonPath('data.shared.report.totals.attended', 0)
            ->assertJsonPath('data.shared.report.revenue.total', '0.00')->assertJsonCount(2, 'data.versions');
        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/report?version=1")->assertJsonPath('data.shared.report.totals.attended', 1);
        $pdf = $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/report/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());

        // Nobody else can read it.
        $this->actingAs(User::factory()->create())->spaJson('GET', "/api/v1/events/{$event}/report")->assertNotFound();
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'report.shared')->where('new_values->event_id', $event)->count());
    }

    public function test_the_summary_covers_the_whole_event_and_keeps_private_notes_private(): void
    {
        $client = User::factory()->create(['first_name' => 'Nina', 'last_name' => 'Reyes']);
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->clientEvent($client, $org, $owner);

        foreach ([['Ceremony', true], ['Staff briefing', false]] as [$title, $visible]) {
            DB::table('event_activities')->insert(['event_id' => $event, 'organization_id' => $org->id, 'title' => $title,
                'visible_to_client' => $visible, 'created_by' => $owner->id, 'activity_at' => now()]);
        }

        $this->travelTo(now()->addHours(2));
        $this->actingAs($owner)->spaJson('POST', "{$base}/complete")->assertOk();

        $mine = $this->actingAs($owner)->spaJson('GET', "{$base}/report")->assertOk()->json('data.summary');
        $this->assertSame('Nina Reyes', $mine['client']);
        $this->assertSame('Wedding', $mine['event_type']);
        $this->assertSame(90, $mine['requested_guests']);
        $this->assertSame('Package', $mine['package']['items'][0]['description']);
        $this->assertCount(2, $mine['program']);

        $this->actingAs($owner)->get("{$base}/report/pdf", self::SPA)->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($owner)->spaJson('POST', "{$base}/report/share", [], $this->key())->assertCreated();
        $shared = $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/report")->assertOk()->json('data.shared.report.summary');
        $this->assertSame(['Ceremony'], array_column($shared['program'], 'title'));
        $this->assertNotNull($shared['package']);

        $this->actingAs($client)->get("/api/v1/events/{$event}/report/pdf", self::SPA)->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_a_private_events_client_sees_the_guest_list_with_seats_but_no_contact_details(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->clientEvent($client, $org, $owner, ['visibility' => 'private']);
        $ana = $this->guest($owner, $base, $this->tier($owner, $base, 'Guest'), 'Ana Cruz');

        $this->actingAs($owner)->spaJson('PATCH', "{$base}/tickets/{$ana['ticket_id']}/seat", ['seat' => ' Table 4 '])->assertOk();

        $guests = $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/guests")->assertOk()->json('data');
        $this->assertSame([['name' => 'Ana Cruz', 'tier' => 'Guest', 'seat' => 'Table 4', 'arrived' => false]], $guests);

        $this->actingAs($owner)->spaJson('GET', "{$base}/guests")->assertOk()->assertJsonPath('data.0.seat', 'Table 4');
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/tickets/{$ana['ticket_id']}/seat", ['seat' => ''])->assertOk();
        $this->actingAs($owner)->spaJson('GET', "{$base}/guests")->assertOk()->assertJsonPath('data.0.seat', null);

        $this->actingAs($owner)->spaJson('PATCH', "{$base}/tickets/{$ana['ticket_id']}/seat", ['seat' => str_repeat('x', 31)])->assertUnprocessable();

        // Check-in staff can't change seats.
        $staff = $this->businessUser();
        $this->addStaff($org, $staff);
        $this->assignToEvent($owner, $org, $event, $staff, ['checkin.perform']);
        $this->actingAs($staff)->spaJson('PATCH', "{$base}/tickets/{$ana['ticket_id']}/seat", ['seat' => 'A1'])->assertForbidden();
    }

    public function test_a_public_events_client_gets_no_guest_list(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->clientEvent($client, $org, $owner, ['visibility' => 'public']);
        $this->guest($owner, $base, $this->tier($owner, $base, 'Guest'), 'Ana Cruz');

        $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/guests")->assertOk()->assertJsonPath('data', []);

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->spaJson('GET', "/api/v1/events/{$event}/guests")->assertNotFound();
    }

    public function test_a_public_events_client_copy_has_numbers_only(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer();
        [$event, $base] = $this->clientEvent($client, $org, $owner, ['visibility' => 'public']);
        $tier = $this->tier($owner, $base, 'General');
        $this->guest($owner, $base, $tier, 'Stranger');
        $this->travelTo(now()->addHours(2));
        $this->actingAs($owner)->spaJson('POST', "{$base}/complete")->assertOk();
        $this->actingAs($owner)->spaJson('POST', "{$base}/report/share", [], $this->key())->assertCreated();

        $report = $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/report")->json('data.shared.report');
        $this->assertSame(1, $report['totals']['expected']);
        $this->assertArrayNotHasKey('attendees', $report);
    }
}
