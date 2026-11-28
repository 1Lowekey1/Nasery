<?php

namespace Tests\Feature\Ticketing;

use App\Mail\EventEmailCodeMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class GuestCheckoutTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Mail is faked: let the reserved example.com addresses through the guard.
        config(['nasery.mail.never_to' => []]);
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    /** A published public event with a free and two paid tiers; returns [org, owner, event, tiers by name]. */
    private function event(): array
    {
        [$org, $owner] = $this->listedOrganizer();
        $day = now('Asia/Manila')->addDays(30)->format('Y-m-d');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Guest Gig', 'visibility' => 'public', 'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T22:00"]],
        ], $this->key())->json('data.id');
        foreach ([['Kids', 0], ['General', 250], ['VIP', 800]] as [$name, $price]) {
            $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/ticket-types",
                ['name' => $name, 'price' => $price, 'capacity' => 10, 'status' => 'on_sale'])->assertCreated();
        }
        $tiers = DB::table('ticket_types')->where('event_id', $event)->pluck('id', 'name');
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return [$org, $owner, $event, $tiers];
    }

    /** Guest confirms an e-mail; returns the verification token. */
    private function confirm(string $event, string $email): string
    {
        $this->spaJson('POST', "/api/v1/public-events/{$event}/email-code", ['email' => $email])->assertOk();
        $code = null;
        Mail::assertSent(EventEmailCodeMail::class, function (EventEmailCodeMail $m) use ($email, &$code) {
            if ($m->hasTo($email)) {
                $code = $m->code;
            }

            return $m->hasTo($email);
        });

        return $this->spaJson('POST', "/api/v1/public-events/{$event}/email-code/verify", ['email' => $email, 'code' => $code])
            ->assertOk()->json('data.verification_token');
    }

    public function test_guests_confirm_every_email_and_pay_for_mixed_tickets(): void
    {
        [, , $event, $tiers] = $this->event();

        // Codes: resend cooldown, wrong code refused, the token only fits its own e-mail.
        $ana = $this->confirm($event, 'ana@example.com');
        $this->spaJson('POST', "/api/v1/public-events/{$event}/email-code", ['email' => 'ana@example.com'])->assertOk(); // new code after verifying
        $this->spaJson('POST', "/api/v1/public-events/{$event}/email-code", ['email' => 'ana@example.com'])->assertStatus(429);
        $this->spaJson('POST', "/api/v1/public-events/{$event}/email-code/verify", ['email' => 'ben@example.com', 'code' => '000000'])->assertUnprocessable();
        $ben = $this->confirm($event, 'ben@example.com');

        $attendees = [
            ['full_name' => 'Ana Cruz', 'email' => 'ana@example.com', 'ticket_type_id' => $tiers['VIP'], 'verification_token' => $ana],
            ['full_name' => 'Ben Lim', 'email' => 'ben@example.com', 'ticket_type_id' => $tiers['General'], 'verification_token' => $ben],
        ];
        $swapped = [[...$attendees[0], 'verification_token' => $ben], $attendees[1]];
        $fields = $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", ['attendees' => $swapped], $this->key())
            ->assertUnprocessable()->json('error.details.fields');
        $this->assertSame(['Confirm this e-mail address with the code we send it.'], $fields['attendees.0.email']);
        // Nobody picks their own price: "attendee_category: free" is simply ignored.
        $res = $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", [
            'attendees' => array_map(fn ($a) => $a + ['attendee_category' => 'free'], $attendees),
        ], $this->key())->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');
        $this->assertSame('1050.00', $res->json('data.checkout.amount'));

        // Tokens are single-use.
        $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", ['attendees' => $attendees], $this->key())->assertUnprocessable();

        // The access key opens only this registration.
        $registration = $res->json('data.registration_id');
        $access = $res->json('data.access');
        $this->getJson("/api/v1/public-registrations/{$registration}?access=".str_repeat('0', 64))->assertNotFound();
        $this->getJson("/api/v1/public-registrations/{$registration}?access={$access}")->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.tickets.0.qr_token', null);

        // Demo checkout pays; the success page then shows valid tickets with QR codes.
        parse_str((string) parse_url($res->json('data.checkout.checkout_url'), PHP_URL_QUERY), $q);
        $this->assertStringContainsString("/event-registration/payment-success?registration={$registration}&access={$access}&payment=", $q['success']);
        $intent = DB::table('payments')->where('id', $q['payment'])->value('paymongo_payment_intent_id');
        $this->postJson($q['action'], ['outcome' => 'paid', 'intent' => $intent])->assertOk();
        $shown = $this->getJson("/api/v1/public-registrations/{$registration}?access={$access}&payment={$q['payment']}")->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.payment.status', 'paid');
        $this->assertCount(2, array_filter($shown->json('data.tickets.*.qr_token')));
        $this->assertNull(DB::table('registrations')->where('id', $registration)->value('purchaser_user_id'));

        // Download and re-send a ticket with the key; another registration's ticket is refused.
        $ticket = $shown->json('data.tickets.0.id');
        $this->get("/api/v1/public-registrations/{$registration}/tickets/{$ticket}/pdf?access={$access}")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        // It was e-mailed on payment, so "send again" right away doesn't send a duplicate.
        $this->assertContains(DB::connection('system')->table('mail_deliveries')->whereJsonContains('ticket_ids', $ticket)->latest('created_at')->value('status'), ['queued', 'sent']);
        $deliveries = DB::connection('system')->table('mail_deliveries')->count();
        $this->spaJson('POST', "/api/v1/public-registrations/{$registration}/tickets/{$ticket}/email", ['access' => $access])->assertOk()
            ->assertJsonPath('data.already_sent', true);
        $this->assertSame($deliveries, DB::connection('system')->table('mail_deliveries')->count());
        // Later, it sends a fresh copy.
        $this->travel(3)->minutes();
        $this->spaJson('POST', "/api/v1/public-registrations/{$registration}/tickets/{$ticket}/email", ['access' => $access])->assertOk()
            ->assertJsonPath('data.queued', 1);
        $other = DB::table('tickets')->where('event_id', '!=', $event)->value('id') ?? (string) Str::uuid7();
        $this->get("/api/v1/public-registrations/{$registration}/tickets/{$other}/pdf?access={$access}")->assertNotFound();
    }

    public function test_a_guest_buyer_confirms_once_and_receives_every_ticket(): void
    {
        [, , $event, $tiers] = $this->event();
        $token = $this->confirm($event, 'buyer@example.com');
        $attendees = [
            ['full_name' => 'Buyer Self', 'ticket_type_id' => $tiers['Kids']],
            ['full_name' => 'Friend Two', 'email' => 'friend@example.com', 'ticket_type_id' => $tiers['Kids']],
            ['full_name' => 'Friend Three', 'ticket_type_id' => $tiers['Kids']],
        ];

        // The token must match the buyer's e-mail.
        $fields = $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", [
            'buyer' => ['name' => 'Buyer Self', 'email' => 'other@example.com', 'verification_token' => $token], 'attendees' => $attendees,
        ], $this->key())->assertUnprocessable()->json('error.details.fields');
        $this->assertSame(['Confirm your e-mail address with the code we send it.'], $fields['buyer.email']);

        $res = $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", [
            'buyer' => ['name' => 'Buyer Self', 'email' => 'Buyer@Example.com', 'verification_token' => $token], 'attendees' => $attendees,
        ], $this->key())->assertCreated()->assertJsonPath('data.status', 'confirmed');
        $registration = $res->json('data.registration_id');
        $this->assertSame('buyer@example.com', DB::table('registrations')->where('id', $registration)->value('purchaser_email'));
        // Every ticket is e-mailed to the confirmed buyer; an attendee's optional address also gets their own ticket.
        $deliveries = DB::connection('system')->table('mail_deliveries')->where('event_id', $event)->get(['recipient', 'ticket_ids']);
        $sent = fn (string $to) => $deliveries->where('recipient', $to)->flatMap(fn ($d) => json_decode($d->ticket_ids, true))->count();
        $this->assertSame(3, $sent('buyer@example.com'));
        $this->assertSame(1, $sent('friend@example.com'));

        // Single use.
        $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", [
            'buyer' => ['name' => 'Buyer Self', 'email' => 'buyer@example.com', 'verification_token' => $token], 'attendees' => $attendees,
        ], $this->key())->assertUnprocessable();
    }

    public function test_organizers_set_how_many_tickets_one_buyer_may_have(): void
    {
        [$org, $owner, $event, $tiers] = $this->event();
        $order = fn (int $n) => ['attendees' => array_map(fn ($i) => ['full_name' => "Guest {$i}", 'email' => null, 'ticket_type_id' => $i % 2 ? $tiers['Kids'] : $tiers['General']], range(1, $n))];
        $buyer = User::factory()->create();

        // Default: 10 per buyer, across all their orders.
        $this->assertSame(10, $this->getJson("/api/v1/public-events/{$event}")->assertOk()->json('data.max_per_buyer'));
        $fields = $this->actingAs($buyer)->spaJson('POST', "/api/v1/public-events/{$event}/checkout", $order(11), $this->key())->assertUnprocessable()->json('error.details.fields');
        $this->assertSame(['This event allows up to 10 tickets per buyer.'], $fields['attendees']);
        $this->actingAs($buyer)->spaJson('POST', "/api/v1/public-events/{$event}/checkout", $order(8), $this->key())->assertCreated();
        $fields = $this->actingAs($buyer)->spaJson('POST', "/api/v1/public-events/{$event}/checkout", $order(3), $this->key())->assertUnprocessable()->json('error.details.fields');
        $this->assertSame(['You already have 8 tickets for this event (up to 10 per buyer), so you can get 2 more.'], $fields['attendees']);

        // A guest is counted by the e-mail they confirmed.
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $guest = fn () => ['buyer' => ['name' => 'Gina', 'email' => 'gina@example.com', 'verification_token' => $this->confirm($event, 'gina@example.com')]];
        $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", [...$order(2), ...$guest()], $this->key())->assertCreated();
        $this->travel(2)->minutes(); // the code's resend wait
        $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", [...$order(9), ...$guest()], $this->key())->assertUnprocessable();

        // The organizer allows 12 (1 to 100): the signed-in buyer may now get 4 more.
        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/events/{$event}", ['max_per_buyer' => 101])->assertUnprocessable();
        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/events/{$event}", ['max_per_buyer' => 12])->assertOk()->assertJsonPath('data.max_per_buyer', 12);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->assertSame(12, $this->getJson("/api/v1/public-events/{$event}")->json('data.max_per_buyer'));
        $this->actingAs($buyer)->spaJson('POST', "/api/v1/public-events/{$event}/checkout", $order(4), $this->key())->assertCreated();
    }

    public function test_free_tickets_are_issued_at_once_and_signed_in_buyers_skip_codes(): void
    {
        [, , $event, $tiers] = $this->event();

        $kid = $this->confirm($event, 'kid@example.com');
        $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", ['attendees' => [
            ['full_name' => 'Kid One', 'email' => 'kid@example.com', 'ticket_type_id' => $tiers['Kids'], 'verification_token' => $kid],
        ]], $this->key())->assertCreated()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.checkout', null);

        // A guest can't skip the codes.
        $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", ['attendees' => [
            ['full_name' => 'No Code', 'email' => 'nocode@example.com', 'ticket_type_id' => $tiers['General']],
        ]], $this->key())->assertUnprocessable();

        // A signed-in, verified buyer registers without codes; the tickets are theirs.
        $buyer = User::factory()->create();
        $this->actingAs($buyer)->spaJson('POST', "/api/v1/public-events/{$event}/checkout", ['attendees' => [
            ['full_name' => 'Friend One', 'email' => null, 'ticket_type_id' => $tiers['Kids']],
            ['full_name' => 'Friend Two', 'email' => 'friend@example.com', 'ticket_type_id' => $tiers['Kids']],
        ]], $this->key())->assertCreated()->assertJsonPath('data.status', 'confirmed');
        $this->assertCount(2, $this->actingAs($buyer)->spaJson('GET', '/api/v1/me/tickets')->json('data'));
    }

    public function test_a_private_event_checks_out_only_through_its_link(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $day = now('Asia/Manila')->addDays(30)->format('Y-m-d');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Family Reunion', 'visibility' => 'private', 'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T22:00"]],
        ], $this->key())->json('data.id');
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/ticket-types",
            ['name' => 'Seat', 'price' => 0, 'capacity' => 10, 'status' => 'on_sale'])->assertCreated();
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();
        $link = $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}")->json('data.private_link_key');
        $seat = DB::table('ticket_types')->where('event_id', $event)->where('name', '!=', 'Guest list')->value('id');
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        $this->spaJson('POST', "/api/v1/public-events/{$event}/email-code", ['email' => 'aunt@example.com'])->assertNotFound();
        $this->spaJson('POST', "/api/v1/public-events/{$event}/email-code", ['email' => 'aunt@example.com', 'key' => $link])->assertOk();
        $code = null;
        Mail::assertSent(EventEmailCodeMail::class, function (EventEmailCodeMail $m) use (&$code) {
            $code = $m->code;

            return true;
        });
        $token = $this->spaJson('POST', "/api/v1/public-events/{$event}/email-code/verify", ['email' => 'aunt@example.com', 'code' => $code, 'key' => $link])
            ->assertOk()->json('data.verification_token');

        $attendees = [['full_name' => 'Aunt May', 'email' => 'aunt@example.com', 'ticket_type_id' => $seat, 'verification_token' => $token]];
        $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", ['attendees' => $attendees], $this->key())->assertNotFound();
        $this->spaJson('POST', "/api/v1/public-events/{$event}/checkout", ['attendees' => $attendees, 'key' => $link], $this->key())
            ->assertCreated()->assertJsonPath('data.status', 'confirmed');
    }
}
