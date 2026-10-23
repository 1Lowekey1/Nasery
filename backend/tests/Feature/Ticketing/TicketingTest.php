<?php

namespace Tests\Feature\Ticketing;

use App\Models\Organization;
use App\Models\User;
use App\Services\Ticketing\QrTokens;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class TicketingTest extends TestCase
{
    use InteractsWithOrganizations;

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function event(User $owner, Organization $org, array $overrides = []): string
    {
        $day = now('Asia/Manila')->addDays(30)->format('Y-m-d');

        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", array_replace([
            'name' => 'Ticketed Concert', 'visibility' => 'public', 'venue_name' => 'Arena',
            'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T23:00"]],
        ], $overrides), $this->key())->assertCreated()->json('data.id');
    }

    private function base(Organization $org, string $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event}";
    }

    private function tier(User $owner, Organization $org, string $event, array $data): string
    {
        return collect($this->actingAs($owner)->spaJson('POST', $this->base($org, $event).'/ticket-types', $data)->assertCreated()->json('data'))
            ->firstWhere('name', $data['name'])['id'];
    }

    private function guest(User $owner, Organization $org, string $event, string $tier, string $name = 'Ana Cruz')
    {
        return $this->actingAs($owner)->spaJson('POST', $this->base($org, $event).'/guests',
            ['full_name' => $name, 'email' => Str::slug($name).'@example.com', 'ticket_type_id' => $tier, 'admission_type' => 'invited'], $this->key());
    }

    public function test_tiers_with_zones_benefits_and_capacity_rules(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->event($owner, $org);
        $base = $this->base($org, $event);

        $vip = $this->tier($owner, $org, $event, [
            'name' => 'VIP', 'zone_name' => 'Front Stage', 'benefits' => ['Meet & greet', ' Free drink ', ''], 'price' => 1500, 'capacity' => 2, 'status' => 'on_sale',
        ]);
        $this->actingAs($owner)->spaJson('GET', "{$base}/ticket-types")->assertOk()
            ->assertJsonPath('data.tiers.0.zone_name', 'Front Stage')
            ->assertJsonPath('data.tiers.0.benefits', ['Meet & greet', 'Free drink'])
            ->assertJsonPath('data.tiers.0.remaining', 2);

        $this->actingAs($owner)->spaJson('POST', "{$base}/ticket-types", ['name' => 'X', 'price' => -1, 'capacity' => 0])->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['price', 'capacity']]]]);

        $this->guest($owner, $org, $event, $vip)->assertCreated();
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/ticket-types/{$vip}", ['capacity' => 0])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('DELETE', "{$base}/ticket-types/{$vip}")->assertConflict();
    }

    public function test_guest_list_issues_qr_tickets_that_can_be_cancelled_and_reissued(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->event($owner, $org, ['visibility' => 'private']);
        $tier = $this->tier($owner, $org, $event, ['name' => 'Guest', 'price' => 0, 'capacity' => 100]);

        $created = $this->guest($owner, $org, $event, $tier)->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^NSR1\.[0-9a-f-]{36}\.[A-Za-z0-9_-]{43}$/', $created['qr_token']);
        $this->assertDatabaseHas('qr_tickets', ['ticket_id' => $created['ticket_id'], 'token_hash' => QrTokens::hash($created['qr_token']), 'status' => 'active']);
        $this->assertSame('invited', DB::table('tickets')->where('id', $created['ticket_id'])->value('admission_type'));

        // The same QR can be shown again; reissuing replaces it.
        $base = $this->base($org, $event);
        $this->actingAs($owner)->spaJson('GET', "{$base}/tickets/{$created['ticket_id']}/qr")->assertJsonPath('data.qr_token', $created['qr_token']);
        $new = $this->actingAs($owner)->spaJson('POST', "{$base}/tickets/{$created['ticket_id']}/reissue-qr")->assertOk()->json('data.qr_token');
        $this->assertNotSame($created['qr_token'], $new);
        $this->assertDatabaseMissing('qr_tickets', ['token_hash' => QrTokens::hash($created['qr_token'])]);

        $this->actingAs($owner)->spaJson('GET', "{$base}/guests?search=ana")->assertOk()->assertJsonPath('data.0.name', 'Ana Cruz')
            ->assertJsonPath('meta.summary.valid', 1);

        $this->actingAs($owner)->spaJson('POST', "{$base}/tickets/{$created['ticket_id']}/cancel", ['reason' => 'Guest can\'t come'])->assertOk();
        $this->assertSame('invalidated', DB::table('qr_tickets')->where('ticket_id', $created['ticket_id'])->value('status'));
        $this->actingAs($owner)->spaJson('POST', "{$base}/tickets/{$created['ticket_id']}/cancel", ['reason' => 'again'])->assertConflict();
    }

    public function test_csv_import_is_all_or_nothing(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->event($owner, $org, ['visibility' => 'private']);
        $guestTier = $this->tier($owner, $org, $event, ['name' => 'Guest', 'price' => 0, 'capacity' => 100]);
        $this->tier($owner, $org, $event, ['name' => 'Family', 'price' => 0, 'capacity' => 100]);
        $url = $this->base($org, $event).'/guests/import';
        $post = fn (string $csv) => $this->actingAs($owner)->post($url, [
            'file' => UploadedFile::fake()->createWithContent('guests.csv', $csv), 'ticket_type_id' => $guestTier, 'admission_type' => 'invited',
        ], [...self::SPA, 'Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()]);

        $post("Full Name,E-mail,Phone,Ticket\nAna Cruz,ana@example.com,0917,\nBen Reyes,not-an-email,,\nCara Lim,,,Cousins")
            ->assertUnprocessable()->assertJsonCount(2, 'error.details.fields.file');
        $this->assertSame(0, DB::table('tickets')->where('event_id', $event)->count());

        $post("\xEF\xBB\xBFname,email,contact,tier\nAna Cruz,ana@example.com,0917,\nBen Reyes,ben@example.com,,family\n=HYPERLINK(\"x\"),,,")
            ->assertCreated()->assertJsonPath('data.imported', 3);
        $this->assertSame(3, DB::table('qr_tickets')->join('tickets', 'tickets.id', '=', 'qr_tickets.ticket_id')->where('tickets.event_id', $event)->count());
        $this->assertSame(1, DB::table('tickets')->join('ticket_types', 'ticket_types.id', '=', 'tickets.ticket_type_id')->where('ticket_types.name', 'Family')->where('tickets.event_id', $event)->count());

        // Excel's semicolon "CSV" with first/last name columns also works; a file without a name column is explained.
        $post("First name;Last name;Email address;Mobile number;Ticket tier\nDina;Uy;dina@example.com;0918;Family\nEli;Tan;;;")
            ->assertCreated()->assertJsonPath('data.imported', 2);
        $this->assertTrue(DB::table('tickets as t')->join('attendees as a', 'a.id', '=', 't.attendee_id')->where('t.event_id', $event)
            ->where('a.full_name', 'Dina Uy')->where('a.contact_number', '0918')->exists());
        $post("email,contact\nfay@example.com,0917")->assertUnprocessable()
            ->assertJsonPath('error.details.fields.file.0', 'The first row needs a "name" column (or "first name" and "last name"). Download the template to see the format.');

        // Exported CSV neutralizes the formula-looking name.
        $csv = $this->actingAs($owner)->get($this->base($org, $event).'/guests/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }

    public function test_capacity_is_enforced_per_tier_and_per_event(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->event($owner, $org, ['overall_capacity' => 3]);
        $small = $this->tier($owner, $org, $event, ['name' => 'Small', 'price' => 0, 'capacity' => 2]);
        $big = $this->tier($owner, $org, $event, ['name' => 'Big', 'price' => 0, 'capacity' => 50]);

        $this->guest($owner, $org, $event, $small, 'One Guest')->assertCreated();
        $this->guest($owner, $org, $event, $small, 'Two Guest')->assertCreated();
        $this->guest($owner, $org, $event, $small, 'Three Guest')->assertStatus(409)->assertJsonPath('error.code', 'CAPACITY_EXCEEDED');

        $this->guest($owner, $org, $event, $big, 'Four Guest')->assertCreated();
        $this->guest($owner, $org, $event, $big, 'Five Guest')->assertStatus(409)->assertJsonPath('error.message', 'This event is full.');
    }

    public function test_public_self_registration_free_and_paid(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->event($owner, $org, ['name' => 'Open Air Fest']);
        $free = $this->tier($owner, $org, $event, ['name' => 'General', 'zone_name' => 'Lawn', 'price' => 0, 'capacity' => 5, 'status' => 'on_sale']);
        $paid = $this->tier($owner, $org, $event, ['name' => 'Premium', 'price' => 800, 'capacity' => 2, 'status' => 'on_sale']);
        $draft = $this->tier($owner, $org, $event, ['name' => 'Secret', 'price' => 0, 'capacity' => 5]);
        $private = $this->event($owner, $org, ['name' => 'Private Party', 'visibility' => 'private']);

        // Not listed until published; private events never.
        $this->getJson('/api/v1/public-events')->assertOk()->assertJsonMissing(['name' => 'Open Air Fest']);
        $this->actingAs($owner)->spaJson('POST', $this->base($org, $event).'/publish')->assertOk();
        $this->actingAs($owner)->spaJson('POST', $this->base($org, $private).'/publish')->assertOk();
        auth()->forgetGuards();

        $this->getJson('/api/v1/public-events')->assertOk()->assertJsonFragment(['name' => 'Open Air Fest'])->assertJsonMissing(['name' => 'Private Party']);
        $page = $this->getJson("/api/v1/public-events/{$event}")->assertOk()->assertJsonCount(2, 'data.tiers')->json('data');
        $this->assertSame('available', collect($page['tiers'])->firstWhere('id', $free)['availability']);
        $this->getJson("/api/v1/public-events/{$private}")->assertNotFound();

        // Registering needs an account.
        $this->spaJson('POST', "/api/v1/public-events/{$event}/register", ['ticket_type_id' => $free, 'attendees' => [['full_name' => 'Nobody']]], $this->key())->assertUnauthorized();

        $fan = User::factory()->create();
        $this->actingAs($fan)->spaJson('POST', "/api/v1/public-events/{$event}/register", ['ticket_type_id' => $draft, 'attendees' => [['full_name' => 'Fan']]], $this->key())->assertNotFound();
        $this->actingAs($fan)->spaJson('POST', "/api/v1/public-events/{$event}/register", [
            'ticket_type_id' => $free, 'attendees' => [['full_name' => 'Fan One'], ['full_name' => 'Fan Two', 'email' => 'two@example.com']],
        ], $this->key())->assertCreated()->assertJsonPath('data.status', 'confirmed');

        $mine = $this->actingAs($fan)->spaJson('GET', '/api/v1/me/tickets')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame('Lawn', $mine[0]['zone']);
        $this->assertNotNull($mine[0]['qr_token']);
        $this->assertTrue($mine[0]['cancellable']); // free ticket
        $this->actingAs(User::factory()->create())->spaJson('GET', '/api/v1/me/tickets')->assertJsonCount(0, 'data');

        // Paid: places are held for 15 minutes; holds count against capacity until they expire.
        $this->actingAs($fan)->spaJson('POST', "/api/v1/public-events/{$event}/register", [
            'ticket_type_id' => $paid, 'attendees' => [['full_name' => 'Ann'], ['full_name' => 'Bea']],
        ], $this->key())->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');
        $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/public-events/{$event}/register", [
            'ticket_type_id' => $paid, 'attendees' => [['full_name' => 'Late Fan']],
        ], $this->key())->assertStatus(409)->assertJsonPath('error.code', 'CAPACITY_EXCEEDED');

        // Use the database clock: capacity reads compare with SQL now(), which is fixed for the whole test transaction.
        DB::table('ticket_holds')->where('ticket_type_id', $paid)->update(['expires_at' => DB::raw("now() - interval '1 minute'")]);
        $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/public-events/{$event}/register", [
            'ticket_type_id' => $paid, 'attendees' => [['full_name' => 'Late Fan']],
        ], $this->key())->assertCreated();

        // Holders can cancel their own free tickets.
        $this->actingAs($fan)->spaJson('POST', "/api/v1/me/tickets/{$mine[0]['id']}/cancel")->assertOk();
        $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/me/tickets/{$mine[1]['id']}/cancel")->assertNotFound();
    }

    public function test_private_events_open_only_with_their_link(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $party = $this->event($owner, $org, ['name' => 'Ana Debut', 'visibility' => 'private']);
        $seat = $this->tier($owner, $org, $party, ['name' => 'Dinner seat', 'price' => 0, 'capacity' => 3, 'status' => 'on_sale']);
        $hidden = $this->tier($owner, $org, $party, ['name' => 'Guest list', 'price' => 0, 'capacity' => 50, 'status' => 'closed']);
        $this->actingAs($owner)->spaJson('POST', $this->base($org, $party).'/publish')->assertOk();
        $key = $this->actingAs($owner)->spaJson('GET', $this->base($org, $party))->assertOk()->json('data.private_link_key');
        auth()->forgetGuards();
        $this->assertNotEmpty($key);

        // Never listed; the page needs the right key.
        $this->getJson('/api/v1/public-events')->assertOk()->assertJsonMissing(['name' => 'Ana Debut']);
        $this->getJson("/api/v1/public-events/{$party}")->assertNotFound();
        $this->getJson("/api/v1/public-events/{$party}?key=wrong-key")->assertNotFound();
        $page = $this->getJson("/api/v1/public-events/{$party}?key={$key}")->assertOk()->assertJsonPath('data.is_private', true)->json('data');
        $this->assertSame([$seat], collect($page['tiers'])->pluck('id')->all()); // the invitation-only tier stays hidden

        // A key opens only its own event.
        $other = $this->event($owner, $org, ['name' => 'Other Party', 'visibility' => 'private']);
        $this->actingAs($owner)->spaJson('POST', $this->base($org, $other).'/publish')->assertOk();
        auth()->forgetGuards();
        $this->getJson("/api/v1/public-events/{$other}?key={$key}")->assertNotFound();

        $fan = User::factory()->create();
        $this->actingAs($fan)->spaJson('POST', "/api/v1/public-events/{$party}/register", ['ticket_type_id' => $seat, 'attendees' => [['full_name' => 'Cousin']]], $this->key())->assertNotFound();
        $this->actingAs($fan)->spaJson('POST', "/api/v1/public-events/{$party}/register", ['ticket_type_id' => $hidden, 'attendees' => [['full_name' => 'Cousin']], 'key' => $key], $this->key())->assertNotFound();
        $this->actingAs($fan)->spaJson('POST', "/api/v1/public-events/{$party}/register", ['ticket_type_id' => $seat, 'attendees' => [['full_name' => 'Cousin']], 'key' => $key], $this->key())
            ->assertCreated()->assertJsonPath('data.status', 'confirmed');
        $this->actingAs($fan)->spaJson('GET', '/api/v1/me/tickets')->assertOk()->assertJsonCount(1, 'data');

        // Public events carry no key.
        $open = $this->event($owner, $org, ['name' => 'Open Fair', 'visibility' => 'public']);
        $this->assertNull($this->actingAs($owner)->spaJson('GET', $this->base($org, $open))->json('data.private_link_key'));
    }

    public function test_sales_window_is_respected(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->event($owner, $org);
        $later = now('Asia/Manila')->addDays(3)->format('Y-m-d\TH:i');
        $tier = $this->tier($owner, $org, $event, ['name' => 'Early', 'price' => 0, 'capacity' => 5, 'status' => 'on_sale', 'sales_start_at' => $later]);
        $this->actingAs($owner)->spaJson('POST', $this->base($org, $event).'/publish')->assertOk();

        $this->getJson("/api/v1/public-events/{$event}")->assertJsonPath('data.tiers.0.availability', 'not_yet');
        $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/public-events/{$event}/register", [
            'ticket_type_id' => $tier, 'attendees' => [['full_name' => 'Too Early']],
        ], $this->key())->assertConflict();
    }

    public function test_event_lifecycle_protects_ticket_holders(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->event($owner, $org);
        $tier = $this->tier($owner, $org, $event, ['name' => 'Guest', 'price' => 0, 'capacity' => 10]);
        $this->actingAs($owner)->spaJson('POST', $this->base($org, $event).'/publish')->assertOk();
        $ticket = $this->guest($owner, $org, $event, $tier)->json('data.ticket_id');

        $this->actingAs($owner)->spaJson('POST', $this->base($org, $event).'/unpublish')->assertConflict();
        $this->actingAs($owner)->spaJson('POST', $this->base($org, $event).'/cancel', ['reason' => 'Typhoon'])->assertOk();

        $this->assertSame('cancelled', DB::table('tickets')->where('id', $ticket)->value('status'));
        $this->assertSame('invalidated', DB::table('qr_tickets')->where('ticket_id', $ticket)->value('status'));
    }

    public function test_door_staff_can_read_the_list_but_not_change_it(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $this->addStaff($org, $door = $this->businessUser());
        $event = $this->event($owner, $org);
        $other = $this->event($owner, $org, ['name' => 'Other']);
        $tier = $this->tier($owner, $org, $event, ['name' => 'Guest', 'price' => 0, 'capacity' => 10]);
        $this->assignToEvent($owner, $org, $event, $door, ['checkin.perform']);

        $this->actingAs($door)->spaJson('GET', $this->base($org, $event).'/guests')->assertOk();
        $this->guest($door, $org, $event, $tier)->assertForbidden();
        $this->actingAs($door)->spaJson('GET', $this->base($org, $other).'/guests')->assertNotFound();
        $this->actingAs($door)->spaJson('POST', $this->base($org, $event).'/ticket-types', ['name' => 'Hack', 'price' => 0, 'capacity' => 1])->assertForbidden();
    }
}
