<?php

namespace Tests\Feature\Notifications;

use App\Mail\NotificationMail;
use App\Mail\TicketMail;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifications\MailGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Factory users have example.* addresses, which MailGuard blocks by default (tested below).
        config(['nasery.mail.never_to' => ['.test']]);
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function local(string $modifier): string
    {
        return now('Asia/Manila')->modify($modifier)->format('Y-m-d\TH:i');
    }

    /** Published event (door open in an hour) with a free tier; returns [eventId, tierId, base url]. */
    private function event(User $owner, Organization $org, array $overrides = []): array
    {
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", array_replace([
            'name' => 'Garden Gala', 'visibility' => 'public', 'venue_name' => 'Pearl Garden',
            'sessions' => [['starts_at' => $this->local('+1 hour'), 'ends_at' => $this->local('+5 hours')]],
        ], $overrides), $this->key())->assertCreated()->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $tier = collect($this->actingAs($owner)->spaJson('POST', "{$base}/ticket-types", ['name' => 'Guest', 'zone_name' => 'Garden', 'price' => 0, 'capacity' => 100, 'status' => 'on_sale'])
            ->assertCreated()->json('data'))->firstWhere('name', 'Guest')['id'];
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();

        return [$event, $tier, $base];
    }

    private function guest(User $owner, string $base, string $tier, string $name, ?string $email, bool $send = true)
    {
        return $this->actingAs($owner)->spaJson('POST', "{$base}/guests",
            ['full_name' => $name, 'email' => $email, 'ticket_type_id' => $tier, 'admission_type' => 'invited', 'send_email' => $send], $this->key());
    }

    public function test_guests_get_their_qr_ticket_by_email_and_the_organizer_sees_delivery(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [, $tier, $base] = $this->event($owner, $org);

        $ana = $this->guest($owner, $base, $tier, 'Ana Cruz', 'ana.cruz@guests.ph')->assertCreated()->json('data.ticket_id');
        Mail::assertSent(TicketMail::class, function (TicketMail $mail) {
            $mail->assertHasSubject('Your ticket for Garden Gala');

            return $mail->hasTo('ana.cruz@guests.ph') && count($mail->attachments()) === 1 && $mail->cards->first()['token'] !== null;
        });

        $ben = $this->guest($owner, $base, $tier, 'Ben Lim', null)->json('data.ticket_id');           // no address
        $cara = $this->guest($owner, $base, $tier, 'Cara Tan', 'cara@party.test')->json('data.ticket_id'); // test domain
        $dan = $this->guest($owner, $base, $tier, 'Dan Uy', 'dan@guests.ph', send: false)->json('data.ticket_id');
        Mail::assertSentCount(1);

        $rows = collect($this->actingAs($owner)->spaJson('GET', "{$base}/guests")->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('sent', $rows[$ana]['email_delivery']['status']);
        $this->assertSame(['suppressed', 'No e-mail address.'], [$rows[$ben]['email_delivery']['status'], $rows[$ben]['email_delivery']['error']]);
        $this->assertSame('suppressed', $rows[$cara]['email_delivery']['status']);
        $this->assertNull($rows[$dan]['email_delivery']);

        // Send it later from the guest list (queued until the request commits, then sent).
        $this->actingAs($owner)->spaJson('POST', "{$base}/tickets/{$dan}/email")->assertOk()->assertJsonPath('data.email_delivery.status', 'queued');
        $this->assertSame('sent', collect($this->actingAs($owner)->spaJson('GET', "{$base}/guests")->json('data'))->firstWhere('id', $dan)['email_delivery']['status']);
        $this->actingAs($owner)->spaJson('POST', "{$base}/tickets/{$ben}/email")->assertOk()->assertJsonPath('message', 'Not sent: No e-mail address.');
        Mail::assertSentCount(2);

        // Printable PDF for the organizer; the holder route refuses someone else's ticket.
        $pdf = $this->actingAs($owner)->spaJson('GET', "{$base}/tickets/{$ana}/pdf");
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->actingAs(User::factory()->create())->spaJson('GET', "/api/v1/me/tickets/{$ana}/pdf")->assertNotFound();
    }

    public function test_self_registration_emails_tickets_and_notifies_in_app(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$event, $tier] = $this->event($owner, $org);
        $fan = User::factory()->create(['email' => 'fan@buyers.ph']);

        $this->actingAs($fan)->spaJson('POST', "/api/v1/public-events/{$event}/register",
            ['ticket_type_id' => $tier, 'attendees' => [['full_name' => 'Fan One'], ['full_name' => 'Fan Two']]], $this->key())->assertCreated();

        // One e-mail to the buyer with both tickets.
        Mail::assertSent(TicketMail::class, fn (TicketMail $m) => $m->hasTo('fan@buyers.ph') && $m->cards->count() === 2);
        $this->actingAs($fan)->spaJson('GET', '/api/v1/me/notifications')->assertOk()
            ->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.type', 'tickets.issued')->assertJsonPath('data.0.title', 'Your 2 tickets for Garden Gala')
            ->assertJsonPath('data.0.url', '/my-tickets');

        $ticket = DB::table('tickets')->where('event_id', $event)->value('id');
        $mine = $this->actingAs($fan)->spaJson('GET', "/api/v1/me/tickets/{$ticket}/pdf");
        $mine->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_in_app_notifications_email_preferences_and_privacy(): void
    {
        config(['nasery.mail.never_to' => []]); // business test accounts use @nasery.test
        [$org, $owner] = $this->listedOrganizer();
        $client = User::factory()->create();
        $inquiry = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(), 'guest_count' => 90, 'location_text' => 'Lanang, Davao City',
        ])->json('data.id');

        // The client turns off quotation e-mails before any arrive.
        $this->actingAs($client)->spaJson('PUT', '/api/v1/me/notification-preferences', ['preferences' => [['type' => 'quotation.received', 'email' => false]]])
            ->assertOk()->assertJsonFragment(['type' => 'quotation.received', 'email' => false]);

        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$inquiry}/submit", ['mode' => 'direct', 'organization_ids' => [$org->id]], $this->key())->assertOk();

        // The organizer hears about the inquiry in-app and by e-mail.
        $this->actingAs($owner)->spaJson('GET', '/api/v1/me/notifications')->assertJsonPath('data.0.type', 'inquiry.received')
            ->assertJsonPath('data.0.title', 'New inquiry: Wedding for 90 guests');
        Mail::assertSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($owner->email) && $m->heading === 'New inquiry: Wedding for 90 guests');

        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$inquiry}/quotations",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 50000]], 'submit' => true], $this->key())->assertCreated();

        // In-app for the client, but no e-mail (their choice).
        $n = $this->actingAs($client)->spaJson('GET', '/api/v1/me/notifications?unread=1')->assertJsonPath('meta.unread_count', 1)
            ->assertJsonPath('data.0.type', 'quotation.received')->json('data.0');
        $this->assertStringContainsString('₱50,000.00', $n['body']);
        Mail::assertNotSent(NotificationMail::class, fn (NotificationMail $m) => $m->hasTo($client->email));
        $this->assertSame(0, DB::table('notification_deliveries')->where('notification_id', $n['id'])->count());

        // Only the recipient can mark it read.
        $this->actingAs($owner)->spaJson('POST', "/api/v1/me/notifications/{$n['id']}/read")->assertOk();
        $this->assertNull(DB::table('notifications')->where('id', $n['id'])->value('read_at'));
        $this->actingAs($client)->spaJson('POST', "/api/v1/me/notifications/{$n['id']}/read")->assertOk()->assertJsonPath('data.count', 0);

        // Critical types can't be switched off; clients don't see organizer types.
        $this->actingAs($owner)->spaJson('PUT', '/api/v1/me/notification-preferences', ['preferences' => [['type' => 'capacity.full', 'email' => false]]])
            ->assertOk()->assertJsonFragment(['type' => 'capacity.full', 'critical' => true, 'email' => true]);
        $this->actingAs($client)->spaJson('PUT', '/api/v1/me/notification-preferences', ['preferences' => [['type' => 'capacity.full', 'email' => false]]])
            ->assertUnprocessable();
    }

    public function test_capacity_alerts_fire_once_per_level(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [, , $base] = $this->event($owner, $org, ['overall_capacity' => 10]);

        $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/headcount", ['count' => 8], $this->key())->assertCreated();
        $this->assertSame(0, DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'like', 'capacity.%')->count());

        $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/headcount", ['count' => 1], $this->key())->assertCreated(); // 90%
        $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/headcount", ['count' => 1], $this->key())->assertCreated(); // full
        $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/headcount", ['count' => 1, 'override_reason' => 'Band'], $this->key())->assertCreated(); // over

        $types = DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'like', 'capacity.%')->orderBy('created_at')->pluck('type')->all();
        $this->assertSame(['capacity.warning', 'capacity.full'], $types);
        $this->assertTrue((bool) DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'capacity.full')->value('is_critical'));
    }

    public function test_day_before_reminders_are_sent_once(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [, $tier, $base] = $this->event($owner, $org, ['sessions' => [['starts_at' => $this->local('+20 hours'), 'ends_at' => $this->local('+24 hours')]]]);
        $this->guest($owner, $base, $tier, 'Ana Cruz', 'ana.cruz@guests.ph')->assertCreated();

        $this->artisan('nasery:reminders:send')->assertSuccessful();
        Mail::assertSent(TicketMail::class, fn (TicketMail $m) => $m->kind === 'ticket_reminder' && str_starts_with($m->subjectLine(), 'Tomorrow: Garden Gala'));
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'team.event_tomorrow')->count());

        $this->artisan('nasery:reminders:send')->assertSuccessful();
        Mail::assertSent(TicketMail::class, fn (TicketMail $m) => $m->kind === 'ticket_reminder', 1);
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'team.event_tomorrow')->count());
    }

    public function test_mail_guard_blocks_test_domains_and_honors_the_allowlist(): void
    {
        config(['nasery.mail.never_to' => ['.test', '.example', '.invalid', '.localhost', '@example.com', '@example.net', '@example.org']]);
        $guard = app(MailGuard::class);

        $this->assertFalse($guard->allows('ana@party.test'));
        $this->assertFalse($guard->allows('ana@example.com'));
        $this->assertFalse($guard->allows('not-an-email'));
        $this->assertTrue($guard->allows('ana@gmail.com'));

        config(['nasery.mail.only_to' => ['me@gmail.com', '@nasery.ph']]);
        $this->assertTrue($guard->allows('ME@gmail.com'));
        $this->assertTrue($guard->allows('panel@nasery.ph'));
        $this->assertFalse($guard->allows('stranger@gmail.com'));
    }
}
