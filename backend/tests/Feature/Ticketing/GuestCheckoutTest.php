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
}
