<?php

namespace Tests\Feature\Billing;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** Billing starts from Free. */
    protected function planForTests(): string
    {
        return 'free';
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function event(User $owner, Organization $org, string $name = 'Gig')
    {
        $day = now('Asia/Manila')->addDays(20)->format('Y-m-d');

        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => $name, 'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T21:00"]],
        ], $this->key());
    }

    /** Check out $plan and press the demo page's Pay button; returns the payment id. */
    private function subscribe(User $owner, Organization $org, string $plan, string $cycle = 'monthly', string $outcome = 'paid'): string
    {
        $res = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/checkout", ['plan' => $plan, 'cycle' => $cycle], $this->key())
            ->assertCreated()->json('data');
        parse_str((string) parse_url($res['checkout_url'], PHP_URL_QUERY), $q);
        $intent = DB::table('payments')->where('id', $q['payment'])->value('paymongo_payment_intent_id');
        $this->postJson($q['action'], ['outcome' => $outcome, 'intent' => $intent])->assertOk();

        return $res['payment_id'];
    }

    private function billing(User $user, Organization $org)
    {
        return $this->actingAs($user)->spaJson('GET', "/api/v1/organizations/{$org->id}/billing")->assertOk();
    }

    public function test_free_limits_block_new_things_only(): void
    {
        [$org, $owner] = $this->listedOrganizer();

        $this->event($owner, $org, 'One')->assertCreated();
        $this->event($owner, $org, 'Two')->assertCreated();
        $this->event($owner, $org, 'Three')->assertStatus(402)
            ->assertJsonPath('error.code', 'PLAN_LIMIT_REACHED')->assertJsonPath('error.details.feature', 'active_event_limit')
            ->assertJsonPath('error.details.limit', 2);

        foreach (range(1, 3) as $i) {
            $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/team/invitations",
                ['email' => $this->businessUser()->email, 'preset' => 'coordinator'])->assertCreated();
        }
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/team/invitations",
            ['email' => $this->businessUser()->email, 'preset' => 'coordinator'])->assertStatus(402)->assertJsonPath('error.details.feature', 'staff_limit');

        foreach (['Homecoming', 'Blessing'] as $type) {
            $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/event-types", ['name' => $type])->assertCreated();
        }
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/event-types", ['name' => 'Gala'])->assertStatus(402);

        foreach (range(1, 5) as $i) {
            $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/price-options", ['name' => "Option {$i}", 'pricing_unit' => 'flat', 'price' => 1000])->assertCreated();
        }
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/price-options", ['name' => 'Option 6', 'pricing_unit' => 'flat', 'price' => 1000])->assertStatus(402);

        // Analytics stay on Free with a 3-month history; quarterly and CSV exports are extras.
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics")->assertOk()
            ->assertJsonPath('data.plan.ranges', ['3m'])->assertJsonCount(3, 'data.period.labels');
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics?range=12m")->assertStatus(402);
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics?range=3m&group=quarter")->assertStatus(402);
        $event = DB::table('events')->where('organization_id', $org->id)->value('id');
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}/report/csv")->assertStatus(402);
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/events/{$event}/report/pdf")->assertOk();

        $usage = collect($this->billing($owner, $org)->assertJsonPath('data.plan.slug', 'free')->assertJsonPath('data.can.manage', true)->json('data.usage'))->keyBy('key');
        $this->assertSame(['used' => 2, 'limit' => 2], ['used' => $usage['active_event_limit']['used'], 'limit' => $usage['active_event_limit']['limit']]);
        $this->assertSame(3, $usage['staff_limit']['used']);
    }

    public function test_upgrade_by_demo_checkout_then_cancel_back_to_free(): void
    {
        $admin = $this->adminUser();
        [$org, $owner] = $this->listedOrganizer();
        $staff = $this->businessUser();
        $this->addStaff($org, $staff);
        $this->event($owner, $org, 'One')->assertCreated();
        $this->event($owner, $org, 'Two')->assertCreated();

        // Only owners pay; Free needs no checkout.
        $this->actingAs($staff)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/checkout", ['plan' => 'starter', 'cycle' => 'monthly'], $this->key())->assertForbidden();
        $this->billing($staff, $org)->assertJsonPath('data.can.manage', false);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/checkout", ['plan' => 'free', 'cycle' => 'monthly'], $this->key())->assertConflict();

        // A declined payment changes nothing; paying switches the plan now.
        $mrr = (float) $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/billing')->json('data.mrr');
        $this->subscribe($owner, $org, 'starter', 'monthly', 'failed');
        $this->billing($owner, $org)->assertJsonPath('data.plan.slug', 'free');
        $payment = $this->subscribe($owner, $org, 'starter');
        $this->actingAs($owner)->spaJson('GET', "/api/v1/me/payments/{$payment}")->assertOk()->assertJsonPath('data.kind', 'subscription')->assertJsonPath('data.status', 'paid');
        $this->billing($owner, $org)->assertJsonPath('data.plan.slug', 'starter')->assertJsonPath('data.subscription.status', 'active')
            ->assertJsonPath('data.invoices.0.status', 'paid')->assertJsonPath('data.invoices.0.amount', 999);
        $this->assertNull(DB::table('platform_fee_snapshots')->where('payment_id', $payment)->value('id')); // no ticket fee on plans
        $this->assertSame(1, DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'billing.update')->count());
        $this->event($owner, $org, 'Three')->assertCreated();
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/analytics?range=12m&group=quarter")->assertOk();
        $this->assertEqualsWithDelta($mrr + 999, $this->actingAs($admin)->spaJson('GET', '/api/v1/admin/billing')->assertOk()->json('data.mrr'), 0.01);

        // Cancel keeps Starter until the period ends; resume undoes it.
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/cancel")->assertOk()->assertJsonPath('data.subscription.cancel_at_period_end', true);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/resume")->assertOk()->assertJsonPath('data.subscription.cancel_at_period_end', false);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/cancel")->assertOk();

        $this->travelTo(now()->addMonth()->addMinute());
        $this->artisan('nasery:billing:cycle')->assertSuccessful();
        $this->billing($owner, $org)->assertJsonPath('data.plan.slug', 'free')->assertJsonPath('data.subscription', null);
        // Nothing made on Starter is lost; new events wait for room.
        $this->assertSame(3, DB::table('events')->where('organization_id', $org->id)->whereNull('deleted_at')->count());
        $this->event($owner, $org, 'Four')->assertStatus(402);
    }

    public function test_renewal_grace_period_then_expiry(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $this->subscribe($owner, $org, 'pro', 'monthly');
        $end = DB::table('subscriptions')->where('organization_id', $org->id)->where('status', 'active')->value('current_period_end');

        // Period over: renewal invoice, grace period, features kept.
        $this->travelTo(now()->addMonth()->addMinute());
        $this->artisan('nasery:billing:cycle')->assertSuccessful();
        $this->billing($owner, $org)->assertJsonPath('data.plan.slug', 'pro')->assertJsonPath('data.subscription.status', 'grace_period')
            ->assertJsonPath('data.open_invoice.amount', 2999)->assertJsonPath('data.open_invoice.plan_slug', 'pro');

        // Paying the renewal moves the period forward from where it ended.
        $this->subscribe($owner, $org, 'pro', 'monthly');
        $sub = DB::table('subscriptions')->where('organization_id', $org->id)->where('status', 'active')->first();
        $this->assertEquals(strtotime($end), strtotime($sub->current_period_start));
        $this->assertSame(1, DB::table('subscriptions')->where('organization_id', $org->id)->whereIn('status', ['active', 'grace_period'])->count());

        // Next period unpaid past the grace days: back to Free.
        $this->travelTo(now()->addMonths(2));
        $this->artisan('nasery:billing:cycle')->assertSuccessful(); // renewal due → grace
        $this->travelTo(now()->addDays(4));
        $this->artisan('nasery:billing:cycle')->assertSuccessful(); // grace over → expired
        $this->billing($owner, $org)->assertJsonPath('data.plan.slug', 'free');
        $this->assertSame('expired', DB::table('subscriptions')->where('id', $sub->id)->value('status'));
        $this->assertGreaterThanOrEqual(3, DB::table('notifications')->where('recipient_user_id', $owner->id)->where('type', 'billing.update')->count());
    }

    public function test_admin_edits_plans(): void
    {
        $admin = $this->adminUser();
        [$org, $owner] = $this->listedOrganizer();
        $plans = collect($this->actingAs($admin)->spaJson('GET', '/api/v1/admin/billing')->assertOk()->json('data.plans'))->keyBy('slug');

        $this->actingAs($admin)->spaJson('PATCH', "/api/v1/admin/plans/{$plans['free']['id']}", ['price_monthly' => 100])->assertConflict();
        $this->actingAs($admin)->spaJson('PATCH', "/api/v1/admin/plans/{$plans['free']['id']}", ['limits' => ['active_event_limit' => 3]])->assertOk()
            ->assertJsonPath('data.limits.active_event_limit', 3);
        $this->actingAs($admin)->spaJson('PATCH', "/api/v1/admin/plans/{$plans['starter']['id']}", ['price_monthly' => 1299, 'limits' => ['staff_limit' => null]])->assertOk()
            ->assertJsonPath('data.price_monthly', 1299)->assertJsonPath('data.limits.staff_limit', null);
        $this->actingAs($owner)->spaJson('PATCH', "/api/v1/admin/plans/{$plans['starter']['id']}", ['price_monthly' => 1])->assertForbidden();

        // Free's new limit applies at once.
        foreach (['Gig A', 'Gig B', 'Gig C'] as $n) {
            $this->event($owner, $org, $n)->assertCreated();
        }
        $this->event($owner, $org, 'Gig D')->assertStatus(402);
        $this->billing($owner, $org)->assertJsonPath('data.plans.1.price_monthly', 1299);
    }

    public function test_upgrading_monthly_to_yearly_credits_the_unused_days(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $this->subscribe($owner, $org, 'starter', 'monthly');
        $this->travelTo(now()->addDays(10));

        $quote = $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/billing/quote?plan=starter&cycle=yearly")->assertOk()
            ->assertJsonPath('data.kind', 'upgrade')->json('data');
        $this->assertGreaterThan(600, $quote['credit']); // ~20 of ~30 days of ₱999
        $this->assertLessThan(700, $quote['credit']);
        $this->assertEqualsWithDelta(9990 - $quote['credit'], $quote['due_now'], 0.01);

        // Pays the difference only; yearly starts now and replaces monthly.
        $payment = $this->subscribe($owner, $org, 'starter', 'yearly');
        $this->assertEqualsWithDelta($quote['due_now'], (float) DB::table('payments')->where('id', $payment)->value('amount'), 0.05);
        $this->billing($owner, $org)->assertJsonPath('data.subscription.cycle', 'yearly')
            ->assertJsonPath('data.invoices.0.list_price', 9990);
        $this->assertSame(1, DB::table('subscriptions')->where('organization_id', $org->id)->whereIn('status', ['active', 'grace_period'])->count());

        // Pro monthly costs less than the yearly credit left: that switch waits for the period end instead.
        $this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/billing/quote?plan=pro&cycle=monthly")->assertOk()
            ->assertJsonPath('data.kind', 'scheduled')->assertJsonPath('data.due_now', 0);
    }

    public function test_downgrades_wait_for_the_period_end(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $this->subscribe($owner, $org, 'pro', 'monthly');
        $end = DB::table('subscriptions')->where('organization_id', $org->id)->where('status', 'active')->value('current_period_end');

        // Scheduled, nothing charged; Pro stays until the end. Resume undoes it.
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/checkout", ['plan' => 'starter', 'cycle' => 'monthly'], $this->key())
            ->assertOk()->assertJsonPath('data.scheduled', true)->assertJsonPath('data.quote.kind', 'scheduled');
        $this->billing($owner, $org)->assertJsonPath('data.plan.slug', 'pro')->assertJsonPath('data.scheduled_change.plan_slug', 'starter');
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/resume")->assertOk()
            ->assertJsonPath('data.scheduled_change', null)->assertJsonPath('data.subscription.cancel_at_period_end', false);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/checkout", ['plan' => 'starter', 'cycle' => 'monthly'], $this->key())->assertOk();

        // Period over: Starter takes over with its first invoice due (grace), then paid.
        $this->travelTo(now()->addMonth()->addMinute());
        $this->artisan('nasery:billing:cycle')->assertSuccessful();
        $this->billing($owner, $org)->assertJsonPath('data.plan.slug', 'starter')->assertJsonPath('data.subscription.status', 'grace_period')
            ->assertJsonPath('data.open_invoice.amount', 999)->assertJsonPath('data.scheduled_change', null);
        $this->subscribe($owner, $org, 'starter', 'monthly');
        $sub = DB::table('subscriptions')->where('organization_id', $org->id)->where('status', 'active')->first();
        $this->assertEquals(strtotime($end), strtotime($sub->current_period_start));
        $this->assertSame(1, DB::table('subscriptions')->where('organization_id', $org->id)->whereIn('status', ['active', 'grace_period', 'incomplete'])->count());

        // Yearly → monthly of the same plan is a downgrade too; cancelling instead means Free at the end.
        $this->subscribe($owner, $org, 'starter', 'yearly'); // upgrade, credited
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/checkout", ['plan' => 'starter', 'cycle' => 'monthly'], $this->key())
            ->assertOk()->assertJsonPath('data.scheduled', true);
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/billing/cancel")->assertOk()
            ->assertJsonPath('data.scheduled_change', null)->assertJsonPath('data.subscription.cancel_at_period_end', true);
        $this->travelTo(now()->addYear()->addDay());
        $this->artisan('nasery:billing:cycle')->assertSuccessful();
        $this->billing($owner, $org)->assertJsonPath('data.plan.slug', 'free');
    }
}
