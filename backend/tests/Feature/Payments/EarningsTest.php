<?php

namespace Tests\Feature\Payments;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** Where the organizer's ticket money goes, and NaSeRy's payouts of their share (0043). */
class EarningsTest extends TestCase
{
    use InteractsWithOrganizations;

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    /** A public event with an ₱800 tier and one paid ₱1,600 purchase; returns [org, owner, event, payment]. */
    private function sale(): array
    {
        [$org, $owner] = $this->listedOrganizer();
        $day = now('Asia/Manila')->addDays(30)->format('Y-m-d');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Earnings Gig', 'visibility' => 'public', 'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T22:00"]],
        ], $this->key())->json('data.id');
        $tier = collect($this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/ticket-types",
            ['name' => 'VIP', 'price' => 800, 'capacity' => 10, 'status' => 'on_sale'])->json('data'))->firstWhere('name', 'VIP')['id'];
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();

        $buyer = User::factory()->create();
        $checkout = $this->actingAs($buyer)->spaJson('POST', "/api/v1/public-events/{$event}/register", [
            'ticket_type_id' => $tier, 'attendees' => [['full_name' => 'Guest 1'], ['full_name' => 'Guest 2']],
        ], $this->key())->json('data.checkout');
        parse_str((string) parse_url($checkout['checkout_url'], PHP_URL_QUERY), $q);
        $intent = DB::table('payments')->where('id', $q['payment'])->value('paymongo_payment_intent_id');
        $this->postJson($q['action'], ['outcome' => 'paid', 'intent' => $intent])->assertOk();

        return [$org, $owner, $event, $checkout['payment_id']];
    }

    public function test_the_organizer_sees_where_the_money_goes_and_what_is_still_owed(): void
    {
        [$org, $owner, $event, $payment] = $this->sale();
        $this->onPlan($org, 'free');
        $admin = User::factory()->admin()->create();
        $fee = (float) DB::table('platform_fee_snapshots')->where('payment_id', $payment)->value('computed_amount');

        // Refund ₱400 (buyer request: NaSeRy keeps its fee).
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/payments/{$payment}/refund",
            ['amount' => 400, 'category' => 'buyer_request', 'reason' => 'Could not attend', 'cancel_tickets' => false], $this->key())->assertOk();

        $share = 1600 - 400 - $fee;
        $money = fn () => $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/earnings")->assertOk()->json('data');
        $m = $money();
        $this->assertEquals([1600, 400, $fee, $share, 0, $share], [$m['gross'], $m['refunded'], $m['platform_fee'], $m['share'], $m['paid_out'], $m['owed']]);
        $this->assertFalse($m['plan']['details']);

        // Free plan: the per-event and monthly detail and the CSV are for paid plans.
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/earnings/details")->assertStatus(402);
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/earnings/export")->assertStatus(402);

        // Admins pay out; never more than owed; the organizer is told.
        $this->actingAs($owner)->spaJson('POST', "/api/v1/admin/organizations/{$org->id}/payouts", ['amount' => 100, 'method' => 'gcash', 'reference' => 'X1'], $this->key())->assertForbidden();
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/organizations/{$org->id}/payouts",
            ['amount' => $share + 1, 'method' => 'gcash', 'reference' => 'GC-0001'], $this->key())->assertUnprocessable();
        $payout = $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/organizations/{$org->id}/payouts",
            ['amount' => 1000, 'method' => 'gcash', 'reference' => 'GC-0001', 'note' => 'Weekly payout'], $this->key())
            ->assertCreated()->assertJsonPath('data.method_label', 'GCash')->json('data.id');
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'payout.update')->count());

        $m = $money();
        $this->assertEquals(1000, $m['paid_out']);
        $this->assertEqualsWithDelta($share - 1000, $m['owed'], 0.01);
        $this->assertSame('GC-0001', $m['payouts'][0]['reference']);
        $row = collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/payouts')->assertOk()->json('data.organizers'))
            ->firstWhere('organization.id', $org->id);
        $this->assertEqualsWithDelta($share - 1000, $row['owed'], 0.01);

        // A mistaken payout is voided (reason required), never deleted; it counts as owed again.
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/payouts/{$payout}/void", ['reason' => 'x'])->assertUnprocessable();
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/payouts/{$payout}/void", ['reason' => 'Wrong GCash number'])->assertOk()->assertJsonPath('data.voided', true);
        $this->actingAs($admin)->spaJson('POST', "/api/v1/admin/payouts/{$payout}/void", ['reason' => 'Wrong GCash number'])->assertConflict();
        $m = $money();
        $this->assertEqualsWithDelta($share, $m['owed'], 0.01);
        $this->assertTrue($m['payouts'][0]['voided']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payout.voided', 'entity_id' => $payout]);
    }

    public function test_paid_plans_get_details_and_the_csv_and_staff_need_finance_access(): void
    {
        [$org, $owner] = $this->sale();
        $this->onPlan($org, 'starter');

        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/earnings/details?months=3")->assertOk()
            ->assertJsonPath('data.events.0.name', 'Earnings Gig')->assertJsonCount(3, 'data.months');
        $csv = $this->actingAs($owner)->get("/api/v1/organizations/{$org->id}/earnings/export")->assertOk();
        $this->assertStringContainsString('Your share', $csv->getContent());
        $this->assertStringContainsString('Earnings Gig', $csv->getContent());

        // A team member without finance access can't see the money.
        $staff = $this->businessUser();
        $this->addStaff($org, $staff);
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$org->id}/earnings")->assertForbidden();
    }
}
