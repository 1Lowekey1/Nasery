<?php

namespace Tests\Feature\Payments;

use App\Mail\NotificationMail;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class PaymentTest extends TestCase
{
    use InteractsWithOrganizations;

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    /** A published public event with an on-sale paid tier; returns [org, owner, event, tier]. */
    private function paidEvent(int $capacity = 10, int $price = 800): array
    {
        [$org, $owner] = $this->listedOrganizer();
        $day = now('Asia/Manila')->addDays(30)->format('Y-m-d');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Paid Gig', 'visibility' => 'public', 'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T22:00"]],
        ], $this->key())->json('data.id');
        $tier = collect($this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/ticket-types",
            ['name' => 'VIP', 'price' => $price, 'capacity' => $capacity, 'status' => 'on_sale'])->json('data'))->firstWhere('name', 'VIP')['id'];
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();

        return [$org, $owner, $event, $tier];
    }

    private function buy(User $buyer, string $event, string $tier, int $people = 2)
    {
        return $this->actingAs($buyer)->spaJson('POST', "/api/v1/public-events/{$event}/register", [
            'ticket_type_id' => $tier, 'attendees' => array_map(fn ($i) => ['full_name' => "Guest {$i}"], range(1, $people)),
        ], $this->key());
    }

    /** Press the demo checkout page's button (the signed URL from checkout_url). */
    private function demo(string $checkoutUrl, string $outcome)
    {
        parse_str((string) parse_url($checkoutUrl, PHP_URL_QUERY), $q);
        $intent = DB::table('payments')->where('id', $q['payment'])->value('paymongo_payment_intent_id');

        return $this->postJson($q['action'], ['outcome' => $outcome, 'intent' => $intent]);
    }

    private function useTestMode(): void
    {
        config(['services.paymongo.driver' => 'test', 'services.paymongo.secret_key' => 'sk_test_demo', 'services.paymongo.webhook_secret' => 'whsk_test']);
        $this->app->forgetInstance(PaymentGateway::class);
    }

    private function signedWebhook(array $event, bool $live = false, ?string $secret = 'whsk_test')
    {
        $payload = json_encode($event);
        $t = time();
        $sig = hash_hmac('sha256', "{$t}.{$payload}", $secret);
        $header = $live ? "t={$t},te=,li={$sig}" : "t={$t},te={$sig},li=";

        return $this->call('POST', '/api/v1/payments/paymongo/webhook', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_PAYMONGO_SIGNATURE' => $header], $payload);
    }

    public function test_demo_checkout_issues_paid_tickets_with_the_platform_fee_deducted(): void
    {
        $this->useTicketFee(0.05);
        [$org, $owner, $event, $tier] = $this->paidEvent();
        $buyer = User::factory()->create();

        $res = $this->buy($buyer, $event, $tier)->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.checkout.amount', '1600.00')
            ->assertJsonPath('data.checkout.mode', 'fake')->json('data');
        $this->assertStringContainsString('/pay/demo?', $res['checkout']['checkout_url']);

        // Reserved, not yet ticketed.
        $this->actingAs($buyer)->spaJson('GET', '/api/v1/me/registrations/pending')->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', '1600.00');
        $this->actingAs($buyer)->spaJson('GET', '/api/v1/me/tickets')->assertJsonCount(0, 'data');

        $this->demo($res['checkout']['checkout_url'], 'paid')->assertOk();
        $this->demo($res['checkout']['checkout_url'], 'paid')->assertOk(); // idempotent

        $payment = DB::table('payments')->where('id', $res['checkout']['payment_id'])->first();
        $this->assertSame('paid', $payment->status);
        $this->assertSame('80.00', DB::table('platform_fee_snapshots')->where('payment_id', $payment->id)->value('computed_amount')); // 5% of ₱1,600
        $this->assertSame(['40.00', '40.00'], DB::table('tickets')->where('registration_id', $res['registration_id'])->orderBy('id')->pluck('platform_fee_amount')->all());
        $this->assertSame('converted', DB::table('ticket_holds')->where('registration_id', $res['registration_id'])->value('status'));

        $this->actingAs($buyer)->spaJson('GET', "/api/v1/me/payments/{$payment->id}")->assertJsonPath('data.status', 'paid')->assertJsonPath('data.registration_status', 'confirmed');
        $mine = $this->actingAs($buyer)->spaJson('GET', '/api/v1/me/tickets')->assertJsonCount(2, 'data')->json('data');
        $this->assertNotNull($mine[0]['qr_token']);
        $this->assertFalse($mine[0]['cancellable']); // paid: refunds go through the organizer

        // Organizer view: gross, fee, net.
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}/payments")->assertOk()
            ->assertJsonPath('data.totals.gross', '1600.00')->assertJsonPath('data.totals.fees', '80.00')->assertJsonPath('data.totals.net', '1520.00');
    }

    public function test_platform_fee_is_returned_only_for_a_valid_reason(): void
    {
        $this->useTicketFee(0.05);
        [$org, $owner, $event, $tier] = $this->paidEvent();
        $res = $this->buy(User::factory()->create(), $event, $tier)->json('data'); // ₱1,600, fee ₱80
        $this->demo($res['checkout']['checkout_url'], 'paid')->assertOk();
        $pay = $res['checkout']['payment_id'];
        $refund = fn (int $amount, string $category) => $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/payments/{$pay}/refund",
            ['amount' => $amount, 'category' => $category, 'reason' => 'Test refund', 'cancel_tickets' => false], $this->key())->assertOk();
        $fees = fn () => DB::table('payment_refunds')->where('payment_id', $pay)->orderBy('created_at')->pluck('fee_portion_refunded')->all();

        $refund(400, 'force_majeure');      // typhoon: a quarter of the fee back
        $refund(400, 'organizer_fault');    // negligence: NaSeRy keeps its fee
        $this->assertSame(['20.00', '0.00'], $fees());
        $this->assertStringStartsWith('Organizer fault: ', DB::table('payment_refunds')->where('payment_id', $pay)->where('fee_portion_refunded', 0)->value('reason'));

        $refund(800, 'payment_error');      // the last refund takes all the fee left
        $this->assertSame(['20.00', '0.00', '60.00'], $fees());

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}/payments")->assertOk()
            ->assertJsonPath('data.totals.fees', '0.00')->assertJsonPath('data.totals.fee_returned', '80.00')
            ->assertJsonPath('data.totals.refunds', '1600.00')->assertJsonPath('data.totals.net', '0.00');
    }

    public function test_a_buyer_asks_for_a_refund_and_the_organizer_declines_or_refunds_it(): void
    {
        [$org, $owner, $event, $tier] = $this->paidEvent();
        $buyer = User::factory()->create();
        $res = $this->buy($buyer, $event, $tier)->json('data');
        $this->demo($res['checkout']['checkout_url'], 'paid')->assertOk();
        $ticket = $this->actingAs($buyer)->spaJson('GET', '/api/v1/me/tickets')->assertJsonPath('data.0.refund.can_request', true)->json('data.0.id');
        $ask = fn () => $this->actingAs($buyer)->spaJson('POST', "/api/v1/me/tickets/{$ticket}/refund-request", ['reason' => 'I can no longer attend']);

        // Only the buyer can ask, and only once at a time.
        $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/me/tickets/{$ticket}/refund-request", ['reason' => 'Not mine'])->assertNotFound();
        $ask()->assertOk();
        $ask()->assertStatus(409);
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'refund.requested')->count());

        // The organizer sees it on the payment and declines it with a note.
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $request = $this->actingAs($owner)->spaJson('GET', "{$base}/payments")->assertOk()
            ->assertJsonPath('data.payments.0.refund_request.reason', 'I can no longer attend')->json('data.payments.0.refund_request.id');
        $this->actingAs($owner)->spaJson('POST', "{$base}/refund-requests/{$request}/decline", ['note' => 'No refunds a week before'])->assertOk();
        $this->actingAs($buyer)->spaJson('GET', '/api/v1/me/tickets')->assertJsonPath('data.0.refund.request_status', 'declined')
            ->assertJsonPath('data.0.refund.response_note', 'No refunds a week before')->assertJsonPath('data.0.refund.can_request', true);

        // Asked again: refunding the payment answers it, and admins hear about the refund.
        $ask()->assertOk();
        $this->actingAs($owner)->spaJson('POST', "{$base}/payments/{$res['checkout']['payment_id']}/refund",
            ['amount' => 1600, 'category' => 'buyer_request', 'reason' => 'Goodwill', 'cancel_tickets' => true], $this->key())->assertOk();
        $this->assertSame(['declined', 'refunded'], DB::table('refund_requests')->where('payment_id', $res['checkout']['payment_id'])->orderBy('status')->pluck('status')->all());
        $this->actingAs($buyer)->spaJson('GET', '/api/v1/me/tickets')->assertJsonPath('data.0.refund.can_request', false);
        $this->assertGreaterThan(0, DB::table('notifications')->where('type', 'admin.refund')->count());
    }

    public function test_a_buyer_without_an_account_is_emailed_about_the_refund(): void
    {
        [$org, $owner, $event, $tier] = $this->paidEvent();
        $res = $this->buy(User::factory()->create(), $event, $tier)->assertCreated()->json('data');
        $this->demo($res['checkout']['checkout_url'], 'paid')->assertOk();
        // As a guest checkout leaves it: no account, only the e-mail they bought with.
        DB::table('registrations')->where('id', $res['registration_id'])->update(['purchaser_user_id' => null, 'purchaser_email' => 'guest.buyer@gmail.com', 'purchaser_name' => 'Gina Cruz']);
        Mail::fake();

        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/payments/{$res['checkout']['payment_id']}/refund",
            ['amount' => 1600, 'category' => 'buyer_request', 'reason' => 'Cannot attend', 'cancel_tickets' => true], $this->key())->assertOk();

        Mail::assertQueued(NotificationMail::class, fn ($m) => $m->hasTo('guest.buyer@gmail.com') && $m->guest
            && str_contains($m->heading, '1,600.00 refunded') && $m->firstName === 'Gina');
        $this->assertSame(0, DB::table('notifications')->where('type', 'payment.refunded')->where('data->payment_id', $res['checkout']['payment_id'])->count());
    }

    public function test_a_cancelled_event_leaves_the_marketplace_and_stops_selling(): void
    {
        [$org, $owner, $event, $tier] = $this->paidEvent();
        $this->getJson('/api/v1/public-events')->assertJsonFragment(['id' => $event]);

        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/cancel", ['reason' => 'Venue unavailable'])->assertOk();

        $this->getJson('/api/v1/public-events')->assertJsonMissing(['id' => $event]);
        $this->getJson("/api/v1/public-events/{$event}")->assertStatus(410)->assertJsonPath('error.message', 'This event was cancelled by its organizer. Ticket sales are closed.');
        $this->buy(User::factory()->create(), $event, $tier)->assertNotFound();
    }

    public function test_demo_links_cannot_be_forged(): void
    {
        [, , $event, $tier] = $this->paidEvent();
        $res = $this->buy(User::factory()->create(), $event, $tier)->json('data');
        parse_str((string) parse_url($res['checkout']['checkout_url'], PHP_URL_QUERY), $q);

        $unsigned = strtok($q['action'], '?');
        $this->postJson($unsigned, ['outcome' => 'paid', 'intent' => 'x'])->assertForbidden();
        $this->postJson($q['action'], ['outcome' => 'paid', 'intent' => 'fake_pi_wrong'])->assertNotFound();
        $this->assertSame('pending', DB::table('payments')->where('id', $q['payment'])->value('status'));
    }

    public function test_failed_payment_can_be_retried_or_the_reservation_cancelled(): void
    {
        [, , $event, $tier] = $this->paidEvent(capacity: 2);
        $buyer = User::factory()->create();
        $res = $this->buy($buyer, $event, $tier)->json('data');

        $this->demo($res['checkout']['checkout_url'], 'failed')->assertOk();
        $this->assertSame('failed', DB::table('payments')->where('id', $res['checkout']['payment_id'])->value('status'));

        // Places are still held for this buyer; a new checkout replaces the old attempt.
        $this->buy(User::factory()->create(), $event, $tier, 1)->assertStatus(409);
        $retry = $this->actingAs($buyer)->spaJson('POST', "/api/v1/me/registrations/{$res['registration_id']}/checkout", [], $this->key())->assertCreated()->json('data');
        $this->assertNotSame($res['checkout']['payment_id'], $retry['payment_id']);

        $this->actingAs($buyer)->spaJson('POST', "/api/v1/me/registrations/{$res['registration_id']}/cancel")->assertOk();
        $this->assertSame('cancelled', DB::table('payments')->where('id', $retry['payment_id'])->value('status'));
        $this->assertSame(0, DB::table('tickets')->where('registration_id', $res['registration_id'])->where('status', 'pending')->count());
        $this->buy(User::factory()->create(), $event, $tier, 2)->assertCreated(); // places released
        $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/me/registrations/{$res['registration_id']}/cancel")->assertNotFound();
    }

    public function test_late_payment_settles_if_places_remain_and_is_refunded_if_not(): void
    {
        [, , $event, $tier] = $this->paidEvent(capacity: 2);
        $late = User::factory()->create();
        $a = $this->buy($late, $event, $tier, 2)->json('data');
        DB::table('ticket_holds')->where('registration_id', $a['registration_id'])->update(['expires_at' => DB::raw("now() - interval '1 minute'")]); // SQL clock (fixed per test transaction)

        // Places are still free: the late payment goes through.
        $this->demo($a['checkout']['checkout_url'], 'paid')->assertOk();
        $this->assertSame('confirmed', DB::table('registrations')->where('id', $a['registration_id'])->value('status'));

        // Sold out while a second buyer's hold had expired: refunded automatically.
        [, , $event2, $tier2] = $this->paidEvent(capacity: 1);
        $slow = $this->buy(User::factory()->create(), $event2, $tier2, 1)->json('data');
        DB::table('ticket_holds')->where('registration_id', $slow['registration_id'])->update(['expires_at' => DB::raw("now() - interval '1 minute'")]); // SQL clock (fixed per test transaction)
        $fast = $this->buy(User::factory()->create(), $event2, $tier2, 1)->json('data');
        $this->demo($fast['checkout']['checkout_url'], 'paid')->assertOk();

        $this->demo($slow['checkout']['checkout_url'], 'paid')->assertOk();
        $this->assertSame('cancelled', DB::table('registrations')->where('id', $slow['registration_id'])->value('status'));
        $this->assertSame('1', (string) DB::table('payment_refunds')->where('payment_id', $slow['checkout']['payment_id'])->count());
        $this->assertSame('0.00', DB::table('payment_refunds')->where('payment_id', $slow['checkout']['payment_id'])->value('fee_portion_refunded')); // no fee was taken
        $this->assertSame(1, DB::table('tickets')->where('event_id', $event2)->where('status', 'valid')->count());
    }

    public function test_paymongo_test_mode_checkout_and_signed_webhooks(): void
    {
        $this->useTestMode();
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response(['data' => ['id' => 'cs_1', 'attributes' => [
                'checkout_url' => 'https://checkout.paymongo.com/cs_1', 'payment_intent' => ['id' => 'pi_test_1'],
            ]]]),
            'api.paymongo.com/v1/payment_intents/pi_test_1' => Http::response(['data' => ['attributes' => [
                'status' => 'succeeded', 'payments' => [['id' => 'pay_1', 'attributes' => ['status' => 'paid']]],
            ]]]),
            'api.paymongo.com/v1/refunds' => Http::response(['data' => ['id' => 'ref_1']]),
        ]);

        [$org, $owner, $event, $tier] = $this->paidEvent();
        $res = $this->buy(User::factory()->create(), $event, $tier)->assertCreated()
            ->assertJsonPath('data.checkout.checkout_url', 'https://checkout.paymongo.com/cs_1')->json('data');

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/checkout_sessions')
            && $r['data']['attributes']['line_items'][0]['amount'] === 80000
            && $r['data']['attributes']['line_items'][0]['quantity'] === 2
            && $r['data']['attributes']['reference_number'] === $res['checkout']['payment_id']);

        $event1 = ['data' => ['id' => 'evt_1', 'attributes' => ['type' => 'checkout_session.payment.paid', 'livemode' => false,
            'data' => ['id' => 'cs_1', 'attributes' => ['payment_intent' => ['id' => 'pi_test_1']]]]]];

        $this->signedWebhook($event1, secret: 'wrong')->assertUnauthorized();
        $this->signedWebhook(['data' => [...$event1['data'], 'attributes' => [...$event1['data']['attributes'], 'livemode' => true]]], live: true)->assertUnauthorized();
        $this->signedWebhook($event1)->assertOk()->assertJsonPath('data.result', 'processed');
        $this->signedWebhook($event1)->assertOk()->assertJsonPath('data.result', 'duplicate');

        $this->assertSame('paid', DB::table('payments')->where('id', $res['checkout']['payment_id'])->value('status'));
        $this->assertSame('processed', DB::table('payment_webhook_events')->where('provider_event_id', 'evt_1')->value('processing_status'));

        // Organizer refund goes to PayMongo and cancels the tickets.
        $base = "/api/v1/organizations/{$org->id}/events/{$event}/payments/{$res['checkout']['payment_id']}/refund";
        $this->actingAs($owner)->spaJson('POST', $base, ['amount' => 5000, 'category' => 'buyer_request', 'reason' => 'Too much'], $this->key())->assertUnprocessable();
        $this->actingAs($owner)->spaJson('POST', $base, ['amount' => 1600, 'reason' => 'No category'], $this->key())->assertUnprocessable()->assertJsonStructure(['error' => ['details' => ['fields' => ['category']]]]);
        $this->actingAs($owner)->spaJson('POST', $base, ['amount' => 1600, 'category' => 'buyer_request', 'reason' => 'Guest cannot attend'], $this->key())->assertOk();
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/refunds') && $r['data']['attributes']['payment_id'] === 'pay_1' && $r['data']['attributes']['amount'] === 160000);
        $this->assertSame('ref_1', DB::table('payment_refunds')->where('payment_id', $res['checkout']['payment_id'])->value('external_reference'));
        $this->assertSame(0, DB::table('tickets')->where('registration_id', $res['registration_id'])->where('status', 'valid')->count());
        $this->assertSame('paid', DB::table('payments')->where('id', $res['checkout']['payment_id'])->value('status')); // never edited
    }

    public function test_return_page_asks_paymongo_when_the_webhook_is_late(): void
    {
        $this->useTestMode();
        Http::fake([
            'api.paymongo.com/v1/checkout_sessions' => Http::response(['data' => ['id' => 'cs_2', 'attributes' => [
                'checkout_url' => 'https://checkout.paymongo.com/cs_2', 'payment_intent' => ['id' => 'pi_test_2'],
            ]]]),
            'api.paymongo.com/v1/payment_intents/pi_test_2' => Http::response(['data' => ['attributes' => ['status' => 'succeeded']]]),
        ]);
        [, , $event, $tier] = $this->paidEvent();
        $buyer = User::factory()->create();
        $res = $this->buy($buyer, $event, $tier)->json('data');

        $this->actingAs($buyer)->spaJson('GET', "/api/v1/me/payments/{$res['checkout']['payment_id']}")->assertOk()
            ->assertJsonPath('data.status', 'paid')->assertJsonPath('data.registration_status', 'confirmed');
        $this->actingAs(User::factory()->create())->spaJson('GET', "/api/v1/me/payments/{$res['checkout']['payment_id']}")->assertNotFound();
    }

    public function test_payments_need_finance_permission_and_live_mode_is_guarded(): void
    {
        [$org, $owner, $event] = $this->paidEvent();
        $this->addStaff($org, $staff = $this->businessUser());
        $this->assignToEvent($owner, $org, $event, $staff, ['checkin.perform']);
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}/payments")->assertForbidden();

        config(['services.paymongo.driver' => 'live', 'services.paymongo.secret_key' => 'sk_live_x']);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->expectException(RuntimeException::class);
        $this->app->make(PaymentGateway::class);
    }

    public function test_demo_checkout_payments_stay_off_paymongo_after_keys_are_added(): void
    {
        [$org, $owner, $event, $tier] = $this->paidEvent();
        $res = $this->buy(User::factory()->create(), $event, $tier)->json('data');
        $this->demo($res['checkout']['checkout_url'], 'paid')->assertOk();

        // Keys added later: refunding that sale must not call PayMongo (it never saw it).
        $this->useTestMode();
        Http::fake();
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/payments/{$res['checkout']['payment_id']}/refund",
            ['amount' => 400, 'category' => 'organizer_fault', 'reason' => 'Demo sale refund', 'cancel_tickets' => false], $this->key())->assertOk();
        Http::assertNothingSent();
        $this->assertStringStartsWith('fake_ref_', DB::table('payment_refunds')->where('payment_id', $res['checkout']['payment_id'])->value('external_reference'));
    }

    public function test_test_mode_refuses_live_keys(): void
    {
        config(['services.paymongo.driver' => 'test', 'services.paymongo.secret_key' => 'sk_live_oops']);
        $this->app->forgetInstance(PaymentGateway::class);
        $this->expectException(RuntimeException::class);
        $this->app->make(PaymentGateway::class);
    }
}
