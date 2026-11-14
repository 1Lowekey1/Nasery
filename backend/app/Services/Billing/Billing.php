<?php

namespace App\Services\Billing;

use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\User;
use App\Services\Admin\PlatformSettings;
use App\Services\Notifications\AdminAlerts;
use App\Services\Payments\PaymentGateway;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Organizer subscriptions (B18; 0020 + 0041). Capstone demo mode: paid
 * through the same checkout as tickets (the fake gateway by default).
 *
 *  - Free needs no subscription row (Entitlements falls back to it).
 *  - New plan (from Free): checkout creates an `incomplete` subscription, an
 *    issued invoice and a pending payment; paid → it starts now.
 *  - Changing a paid plan (user decision 2026-09-26, common practice):
 *      upgrade (higher plan, or monthly → yearly): starts NOW and the unused
 *        days of the current period are credited ("proration"), so the
 *        organizer never pays twice for the same days;
 *      downgrade (lower plan, or yearly → monthly), or an upgrade whose
 *        credit would exceed the new price: SCHEDULED for the end of the
 *        current period (the paid period is kept, nothing is refunded); a
 *        scheduled switch is an `incomplete` row marked with
 *        downgraded_from_plan_id and starting at the period end.
 *    quote() shows the numbers before anyone pays.
 *  - Cancel: stays on the plan until the period ends, then back to Free.
 *    Resume undoes a cancel or a scheduled switch before that.
 *  - Renewal (hourly job): at period end a renewal invoice is issued and the
 *    subscription enters its grace period (platform setting, default 3
 *    days) with its features kept; paying moves the period forward;
 *    otherwise it expires and the organization is on Free. There is no
 *    stored card in demo mode, so renewals are paid by the owner.
 *  - Owners manage billing; every member can see the plan and usage.
 *
 * Subscriptions, invoices and payments have no app-role write grant, so
 * writes run in the system context after these checks.
 */
class Billing
{
    public function __construct(
        private readonly DatabaseContext $db,
        private readonly AuditLogger $audit,
        private readonly PaymentGateway $gateway,
        private readonly Entitlements $entitlements,
        private readonly OrganizationAccess $access,
        private readonly AdminAlerts $alerts,
        private readonly PlatformSettings $settings,
    ) {}

    /** @return array<string, mixed> */
    public function overview(User $user, Organization $org): array
    {
        $current = $this->entitlements->for($org);
        $used = $this->entitlements->usage($org);

        return $this->db->system('billing.overview', fn () => [
            'plan' => $this->presentPlan($current['plan']),
            'subscription' => $current['subscription'] ? $this->presentSubscription($current['subscription']) : null,
            'usage' => collect(Entitlements::LIMITS)->map(fn ($meta, $key) => $meta + [
                'key' => $key,
                'limit' => $current['limits'][$key] ?? null,
                'used' => $key === 'analytics_history_months' ? null : ($used[$key] ?? 0),
            ])->values(),
            'features' => collect(Entitlements::FEATURES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'included' => $current['features'][$key] ?? false])->values(),
            'plans' => $this->plans(),
            'open_invoice' => $this->openInvoice($org->id),
            'scheduled_change' => $this->scheduled($org->id),
            'payment_mode' => $this->gateway->mode(),
            // NaSeRy's current cut of each online ticket sale (same for every plan); null when the fee is off.
            'fee_percent' => (function () {
                $fee = DB::table('platform_fee_configs')->where('effective_from', '<=', now())
                    ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', now()))->orderByDesc('effective_from')->first();

                return $fee && $fee->is_enabled && $fee->percentage_rate !== null ? round((float) $fee->percentage_rate * 100, 2) : null;
            })(),
            'invoices' => DB::table('subscription_invoices as i')->join('subscriptions as s', 's.id', '=', 'i.subscription_id')
                ->join('plans as p', 'p.id', '=', 's.plan_id')->where('s.organization_id', $org->id)->whereIn('i.status', ['issued', 'paid'])
                ->orderByDesc('i.created_at')->limit(24)
                ->get(['i.*', 'p.name as plan', 's.billing_cycle', 'p.price_monthly', 'p.price_yearly'])->map(fn ($i) => $this->presentInvoice($i))->values(),
            'can' => ['manage' => $this->access->isOwner($user, $org)],
            'grace_days' => $this->graceDays(),
        ]);
    }

    /** Active plans with their entitlements, cheapest first (public to organizers). */
    public function plans(bool $includeInactive = false): array
    {
        return $this->db->system('billing.plans', function () use ($includeInactive) {
            $plans = DB::table('plans')->whereNull('deleted_at')->when(! $includeInactive, fn ($q) => $q->where('is_active', true))->orderBy('price_monthly')->get();
            $rows = DB::table('plan_entitlements as e')->join('features as f', 'f.id', '=', 'e.feature_id')
                ->whereIn('e.plan_id', $plans->pluck('id'))->get(['e.plan_id', 'f.key', 'f.value_type', 'e.boolean_value', 'e.numeric_value'])->groupBy('plan_id');

            return $plans->map(fn ($p) => $this->presentPlan($p) + [
                'limits' => collect($rows->get($p->id, []))->where('value_type', 'numeric')
                    ->mapWithKeys(fn ($r) => [$r->key => $r->numeric_value === null ? null : (int) $r->numeric_value]),
                'features' => collect($rows->get($p->id, []))->where('value_type', 'boolean')->mapWithKeys(fn ($r) => [$r->key => (bool) $r->boolean_value]),
            ])->values()->all();
        });
    }

    /**
     * What choosing $planSlug/$cycle would do right now, before paying.
     *
     * kind: new | upgrade | scheduled | renewal
     *
     * @return array<string, mixed>
     */
    public function quote(Organization $org, string $planSlug, string $cycle): array
    {
        $plan = $this->paidPlan($planSlug);
        $price = (float) ($cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly);
        $live = $this->entitlements->for($org)['subscription'];
        $base = ['plan' => $plan->name, 'plan_slug' => $plan->slug, 'cycle' => $cycle, 'price' => $price, 'credit' => 0.0,
            'due_now' => $price, 'starts_at' => now()->toIso8601String(), 'kind' => 'new'];

        if ($live === null) {
            return $base + ['message' => "{$plan->name} ({$cycle}) starts as soon as you pay ".$this->peso($price).'.'];
        }

        if ($live->plan_id === $plan->id && $live->billing_cycle === $cycle) {
            if ($live->status === 'active') {
                throw new ApiException(ErrorCode::Conflict, "You're already on {$plan->name} ({$cycle}).", 409);
            }
            $open = $this->db->system('billing.quote_open', fn () => DB::table('subscription_invoices')->where('subscription_id', $live->id)
                ->where('status', 'issued')->orderByDesc('created_at')->first());

            return ['kind' => 'renewal', 'due_now' => (float) ($open->amount ?? $price)] + $base
                + ['message' => 'Pay your renewal of '.$this->peso((float) ($open->amount ?? $price)).' to keep '.$plan->name.'.'];
        }

        // An overdue plan has no paid days left to credit: the new plan simply replaces it.
        if ($live->status !== 'active') {
            return $base + ['message' => "{$plan->name} ({$cycle}) replaces your overdue plan as soon as you pay ".$this->peso($price).'.'];
        }

        $current = $this->db->system('billing.quote_current', fn () => DB::table('plans')->where('id', $live->plan_id)->first());
        $tier = (float) $plan->price_monthly <=> (float) $current->price_monthly;
        $upgrade = $tier > 0 || ($tier === 0 && $cycle === 'yearly' && $live->billing_cycle === 'monthly');
        $credit = $this->unusedCredit($live);

        if ($upgrade && $credit < $price) {
            $due = round($price - $credit, 2);

            return ['kind' => 'upgrade', 'credit' => $credit, 'due_now' => $due] + $base
                + ['message' => "{$plan->name} ({$cycle}) starts now. The unused part of your {$current->name} plan (".$this->peso($credit).') is credited, so you pay '.$this->peso($due).'.'];
        }

        return ['kind' => 'scheduled', 'due_now' => 0.0, 'starts_at' => CarbonImmutable::parse($live->current_period_end)->toIso8601String()] + $base
            + ['message' => "You keep {$current->name} until ".$this->when($live->current_period_end).", then switch to {$plan->name} ({$cycle}) and pay ".$this->peso($price).' then. Nothing is charged now.'];
    }

    /**
     * Choose a plan: pay now (new plan, upgrade with credit, renewal) or
     * schedule the switch for the end of the period (downgrade).
     *
     * @return array<string, mixed> scheduled + quote, and for payments: payment_id, checkout_url, amount, mode
     */
    public function checkout(User $owner, Organization $org, string $planSlug, string $cycle): array
    {
        $this->assertOwner($owner, $org);
        $quote = $this->quote($org, $planSlug, $cycle);
        $plan = $this->paidPlan($planSlug);
        $live = $this->entitlements->for($org)['subscription'];

        if ($quote['kind'] === 'scheduled') {
            $this->scheduleChange($org, $live, $plan, $cycle);

            return ['scheduled' => true, 'quote' => $quote];
        }

        $invoiceId = $this->db->system('billing.checkout', function () use ($org, $plan, $cycle, $live, $quote) {
            if ($quote['kind'] === 'renewal') {
                $open = DB::table('subscription_invoices')->where('subscription_id', $live->id)->where('status', 'issued')->orderByDesc('created_at')->first();
                if ($open) {
                    return $open->id;
                }
            }

            $this->abandonCheckouts($org->id);
            $now = now();
            $end = $cycle === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth();
            $subId = (string) Str::uuid7();
            DB::table('subscriptions')->insert(['id' => $subId, 'organization_id' => $org->id, 'plan_id' => $plan->id, 'billing_cycle' => $cycle,
                'status' => 'incomplete', 'current_period_start' => $now, 'current_period_end' => $end, 'created_at' => $now, 'updated_at' => $now]);
            $invoiceId = (string) Str::uuid7();
            DB::table('subscription_invoices')->insert(['id' => $invoiceId, 'subscription_id' => $subId, 'amount' => number_format($quote['due_now'], 2, '.', ''),
                'billing_period_start' => $now, 'billing_period_end' => $end, 'status' => 'issued', 'issued_at' => $now, 'created_at' => $now]);

            return $invoiceId;
        });

        if ($quote['due_now'] <= 0) {
            // A plan priced at zero by an admin: nothing to collect.
            $this->activateInvoice($invoiceId, 0.0, 'free_of_charge');

            return ['scheduled' => false, 'quote' => $quote, 'payment_id' => null, 'checkout_url' => null, 'amount' => '0.00', 'mode' => $this->gateway->mode()];
        }

        return ['scheduled' => false, 'quote' => $quote]
            + $this->pay($org, $invoiceId, $quote['due_now'], $plan->name, $cycle, ['kind' => $quote['kind'], 'credit' => $quote['credit'], 'list_price' => $quote['price']]);
    }

    /** Called by Payments::settle for a subscription payment (inside no transaction of its own). */
    public function settleInvoice(Payment $payment, string $source): void
    {
        $this->activateInvoice($payment->subscription_invoice_id, (float) $payment->amount, $source);
    }

    /** Mark the invoice paid and put its subscription in force (idempotent). */
    private function activateInvoice(string $invoiceId, float $amount, string $source): void
    {
        $result = $this->db->system('billing.settle', function () use ($invoiceId) {
            $invoice = DB::table('subscription_invoices')->where('id', $invoiceId)->lockForUpdate()->first();
            if ($invoice === null || $invoice->status === 'paid') {
                return null;
            }
            $sub = DB::table('subscriptions')->where('id', $invoice->subscription_id)->lockForUpdate()->first();
            DB::table('subscription_invoices')->where('id', $invoice->id)->update(['status' => 'paid']);
            $now = now();

            if ($sub->status === 'grace_period' || $sub->status === 'payment_failed' || $sub->status === 'active') {
                // Renewal (or a scheduled switch that just began): the period moves forward from where it ended.
                $start = CarbonImmutable::parse($sub->current_period_end);
                $end = $sub->billing_cycle === 'yearly' ? $start->addYear() : $start->addMonth();
                DB::table('subscriptions')->where('id', $sub->id)->update(['status' => 'active', 'grace_period_ends_at' => null,
                    'current_period_start' => $start, 'current_period_end' => $end, 'downgraded_from_plan_id' => null, 'updated_at' => $now]);
            } else {
                // New plan or upgrade: replace whatever was live (its unused days were credited), start now.
                $previous = DB::table('subscriptions')->where('organization_id', $sub->organization_id)->where('id', '<>', $sub->id)
                    ->whereIn('status', ['active', 'payment_failed', 'grace_period'])->first();
                if ($previous) {
                    DB::table('subscriptions')->where('id', $previous->id)->update(['status' => 'cancelled', 'cancelled_at' => $now, 'grace_period_ends_at' => null, 'updated_at' => $now]);
                    DB::table('subscription_invoices')->where('subscription_id', $previous->id)->where('status', 'issued')->update(['status' => 'void']);
                }
                $this->dropScheduled($sub->organization_id);
                $end = $sub->billing_cycle === 'yearly' ? $now->copy()->addYear() : $now->copy()->addMonth();
                DB::table('subscriptions')->where('id', $sub->id)->update(['status' => 'active', 'current_period_start' => $now, 'current_period_end' => $end, 'updated_at' => $now]);
            }

            return DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')->where('s.id', $sub->id)->first(['s.*', 'p.name as plan']);
        });

        if ($result === null) {
            return;
        }
        $this->audit->record('subscription.paid', ['subscriptions', $result->id],
            new: ['plan' => $result->plan, 'cycle' => $result->billing_cycle, 'until' => $result->current_period_end, 'amount' => $amount, 'source' => $source, 'mode' => $this->gateway->mode()],
            organizationId: $result->organization_id);
        $this->alerts->billingUpdate($result->organization_id, "You're on {$result->plan}",
            'Payment received ('.$this->peso($amount).'). Your plan runs until '.$this->when($result->current_period_end).'.');
    }

    public function cancel(User $owner, Organization $org): array
    {
        return $this->setCancel($owner, $org, true);
    }

    public function resume(User $owner, Organization $org): array
    {
        return $this->setCancel($owner, $org, false);
    }

    /**
     * Hourly: renewals into grace, grace into expiry, cancellations at period
     * end, and abandoned checkouts.
     *
     * @return array<string, int>
     */
    public function runCycle(): array
    {
        $now = now();
        $grace = $this->graceDays();
        $n = ['renewals_due' => 0, 'switched' => 0, 'ended' => 0, 'expired' => 0, 'abandoned' => 0];

        $due = $this->db->system('billing.cycle_due', fn () => DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.status', 'active')->where('s.current_period_end', '<=', $now)->get(['s.*', 'p.name as plan', 'p.price_monthly', 'p.price_yearly']));
        foreach ($due as $sub) {
            if ($sub->cancel_at_period_end && $this->startScheduled($sub, $grace)) {
                $n['switched']++;

                continue;
            }
            if ($sub->cancel_at_period_end) {
                $this->db->system('billing.cycle_cancel', fn () => DB::table('subscriptions')->where('id', $sub->id)
                    ->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]));
                $this->audit->record('subscription.ended', ['subscriptions', $sub->id], new: ['plan' => $sub->plan], organizationId: $sub->organization_id, actorUserId: null);
                $this->alerts->billingUpdate($sub->organization_id, "Your {$sub->plan} plan ended",
                    "You're on Free now. Everything you made stays; new items follow Free's limits. Upgrade anytime from Plan & Billing.");
                $n['ended']++;

                continue;
            }
            $amount = (float) ($sub->billing_cycle === 'yearly' ? $sub->price_yearly : $sub->price_monthly);
            $until = CarbonImmutable::parse($sub->current_period_end)->addDays($grace);
            $this->db->system('billing.cycle_renew', function () use ($sub, $amount, $until, $now) {
                $start = CarbonImmutable::parse($sub->current_period_end);
                DB::table('subscription_invoices')->insert(['id' => (string) Str::uuid7(), 'subscription_id' => $sub->id, 'amount' => number_format($amount, 2, '.', ''),
                    'billing_period_start' => $start, 'billing_period_end' => $sub->billing_cycle === 'yearly' ? $start->addYear() : $start->addMonth(),
                    'status' => 'issued', 'issued_at' => $now, 'created_at' => $now]);
                DB::table('subscriptions')->where('id', $sub->id)->update(['status' => 'grace_period', 'grace_period_ends_at' => $until, 'updated_at' => $now]);
            });
            $this->audit->record('subscription.renewal_due', ['subscriptions', $sub->id], new: ['amount' => $amount, 'grace_until' => $until->toIso8601String()], organizationId: $sub->organization_id, actorUserId: null);
            $this->alerts->billingUpdate($sub->organization_id, "Renew your {$sub->plan} plan",
                'Your renewal of ₱'.number_format($amount, 2).' is due. Pay by '.$this->when($until->toIso8601String()).' to keep your plan; after that you move to Free.');
            $n['renewals_due']++;
        }

        $lapsed = $this->db->system('billing.cycle_lapsed', fn () => DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.status', 'grace_period')->where('s.grace_period_ends_at', '<=', $now)->get(['s.*', 'p.name as plan']));
        foreach ($lapsed as $sub) {
            $this->db->system('billing.cycle_expire', function () use ($sub, $now) {
                DB::table('subscription_invoices')->where('subscription_id', $sub->id)->where('status', 'issued')->update(['status' => 'void']);
                DB::table('subscriptions')->where('id', $sub->id)->update(['status' => 'expired', 'grace_period_ends_at' => null, 'updated_at' => $now]);
            });
            $this->audit->record('subscription.expired', ['subscriptions', $sub->id], new: ['plan' => $sub->plan], organizationId: $sub->organization_id, actorUserId: null);
            $this->alerts->billingUpdate($sub->organization_id, "Your {$sub->plan} plan expired",
                "The renewal wasn't paid, so you're on Free now. Everything you made stays. Upgrade anytime from Plan & Billing.");
            $n['expired']++;
        }

        $n['abandoned'] = $this->db->system('billing.cycle_abandoned', function () use ($now) {
            // Unpaid checkouts only; scheduled switches (marked) wait for their period end.
            $old = DB::table('subscriptions')->where('status', 'incomplete')->whereNull('downgraded_from_plan_id')
                ->where('created_at', '<=', $now->copy()->subDay())->pluck('id');
            DB::table('subscription_invoices')->whereIn('subscription_id', $old)->where('status', 'issued')->update(['status' => 'void']);

            return DB::table('subscriptions')->whereIn('id', $old)->update(['status' => 'expired', 'updated_at' => $now]);
        });

        return $n;
    }

    // ---- Admin ---------------------------------------------------------------

    /** @return array<string, mixed> */
    public function adminOverview(): array
    {
        return $this->db->system('billing.admin', function () {
            $live = DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
                ->whereIn('s.status', ['active', 'payment_failed', 'grace_period'])->get(['s.status', 's.billing_cycle', 's.cancel_at_period_end', 'p.slug', 'p.price_monthly', 'p.price_yearly']);
            $orgs = DB::table('organizations')->whereNull('deleted_at')->where('approval_status', 'approved')->count();

            return [
                'plans' => collect($this->plans(true))->map(fn ($p) => $p + ['subscribers' => $live->where('slug', $p['slug'])->count()])->values(),
                'free_organizers' => max(0, $orgs - $live->count()),
                'mrr' => round($live->sum(fn ($s) => $s->billing_cycle === 'yearly' ? (float) $s->price_yearly / 12 : (float) $s->price_monthly), 2),
                'in_grace' => $live->where('status', 'grace_period')->count(),
                'cancelling' => $live->where('cancel_at_period_end', true)->count(),
                'paid_this_month' => round((float) DB::table('payments')->whereNotNull('subscription_invoice_id')->where('status', 'paid')
                    ->where('paid_at', '>=', CarbonImmutable::now('Asia/Manila')->startOfMonth()->utc())->sum('amount'), 2),
                'recent' => DB::table('subscription_invoices as i')->join('subscriptions as s', 's.id', '=', 'i.subscription_id')
                    ->join('plans as p', 'p.id', '=', 's.plan_id')->join('organizations as o', 'o.id', '=', 's.organization_id')
                    ->whereIn('i.status', ['issued', 'paid'])->orderByDesc('i.created_at')->limit(15)
                    ->get(['i.*', 'p.name as plan', 's.billing_cycle', 'o.display_name as organization'])
                    ->map(fn ($i) => $this->presentInvoice($i) + ['organization' => $i->organization])->values(),
            ];
        });
    }

    /**
     * Change a plan's prices, availability and limits. Existing subscribers
     * keep their current period's price; renewals use the new one.
     *
     * @param  array{price_monthly?: float, price_yearly?: float, is_active?: bool, limits?: array<string, ?int>, features?: array<string, bool>}  $data
     */
    public function updatePlan(string $planId, array $data, User $by): array
    {
        $plan = $this->db->system('billing.plan_find', fn () => DB::table('plans')->where('id', $planId)->whereNull('deleted_at')->first())
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        if ($plan->is_default_free && (($data['is_active'] ?? true) === false || ($data['price_monthly'] ?? 0) > 0 || ($data['price_yearly'] ?? 0) > 0)) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, 'The Free plan stays free and available.', 409);
        }

        $this->db->system('billing.plan_update', function () use ($plan, $data) {
            $columns = array_intersect_key($data, array_flip(['price_monthly', 'price_yearly', 'is_active']));
            if ($columns !== []) {
                DB::table('plans')->where('id', $plan->id)->update($columns);
            }
            $features = DB::table('features')->pluck('id', 'key');
            $set = function (string $key, array $values) use ($plan, $features) {
                if (! isset($features[$key])) {
                    return;
                }
                $where = ['plan_id' => $plan->id, 'feature_id' => $features[$key]];
                DB::table('plan_entitlements')->where($where)->exists()
                    ? DB::table('plan_entitlements')->where($where)->update($values)
                    : DB::table('plan_entitlements')->insert($where + $values + ['id' => (string) Str::uuid7(), 'created_at' => now()]);
            };
            foreach (($data['limits'] ?? []) as $key => $value) {
                $set($key, ['numeric_value' => $value, 'boolean_value' => null]);
            }
            foreach (($data['features'] ?? []) as $key => $value) {
                $set($key, ['boolean_value' => $value, 'numeric_value' => null]);
            }
        });
        $this->audit->record('plan.updated', ['plans', $plan->id], old: ['price_monthly' => (float) $plan->price_monthly, 'price_yearly' => (float) $plan->price_yearly, 'is_active' => (bool) $plan->is_active], new: $data, actorUserId: $by->id);

        return collect($this->plans(true))->firstWhere('id', $plan->id);
    }

    // ---- Internals -------------------------------------------------------------

    /** @return array{payment_id: string, checkout_url: string, amount: string, mode: string} */
    private function pay(Organization $org, string $invoiceId, float $amount, string $planName, string $cycle, array $details = []): array
    {
        $this->db->system('billing.pay_cleanup', fn () => DB::table('payments')->where('subscription_invoice_id', $invoiceId)->where('status', PaymentStatus::Pending->value)
            ->update(['status' => PaymentStatus::Cancelled->value, 'failure_reason' => 'replaced by a new checkout', 'updated_at' => now()]));

        $payment = (new Payment)->forceFill([
            'id' => (string) Str::uuid7(),
            'subscription_invoice_id' => $invoiceId,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => 'PHP',
            'status' => PaymentStatus::Pending,
        ]);
        $owner = $this->db->system('billing.owner', fn () => DB::table('organization_members as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->where('m.organization_id', $org->id)->where('m.is_primary_owner', true)->first(['u.first_name', 'u.last_name', 'u.email', 'u.phone']));
        $return = rtrim((string) config('nasery.auth.frontend_url'), '/').'/organizer/billing?payment='.$payment->id;
        $session = $this->gateway->createCheckout($payment, [[
            'name' => "NaSeRy {$planName} ({$cycle})",
            'amount_centavos' => (int) round($amount * 100),
            'quantity' => 1,
        ]], ['name' => trim(($owner->first_name ?? '').' '.($owner->last_name ?? '')) ?: $org->display_name, 'email' => $owner->email ?? null, 'phone' => $owner->phone ?? null],
            $return, $return.'&cancelled=1');

        $this->db->system('billing.pay_create', fn () => $payment->forceFill(['paymongo_payment_intent_id' => $session->intentId])->save());
        $this->audit->record('subscription.checkout_started', $payment, new: ['plan' => $planName, 'cycle' => $cycle, 'amount' => $payment->amount, 'mode' => $this->gateway->mode()] + $details, organizationId: $org->id);

        return ['payment_id' => $payment->id, 'checkout_url' => $session->checkoutUrl, 'amount' => $payment->amount, 'mode' => $this->gateway->mode()];
    }

    private function setCancel(User $owner, Organization $org, bool $cancel): array
    {
        $this->assertOwner($owner, $org);
        $sub = $this->entitlements->for($org)['subscription'];
        if ($sub === null || $sub->status !== 'active') {
            throw new ApiException(ErrorCode::InvalidStateTransition, $cancel ? "There's no active paid plan to cancel." : 'There is nothing to resume.', 409);
        }
        // A scheduled switch is dropped by both: cancel means Free instead, resume means stay.
        if ($this->scheduled($org->id) !== null) {
            $this->db->system('billing.drop_scheduled', function () use ($org, $sub, $cancel) {
                $this->dropScheduled($org->id);
                DB::table('subscriptions')->where('id', $sub->id)->update(['cancel_at_period_end' => $cancel, 'cancelled_at' => $cancel ? now() : null, 'updated_at' => now()]);
            });
            $this->audit->record($cancel ? 'subscription.cancel_scheduled' : 'subscription.change_undone', ['subscriptions', $sub->id], organizationId: $org->id);

            return $this->overview($owner, $org);
        }
        if ((bool) $sub->cancel_at_period_end === $cancel) {
            throw new ApiException(ErrorCode::Conflict, $cancel ? 'Your plan already ends at the end of this period.' : 'Your plan already renews.', 409);
        }
        $this->db->system('billing.cancel', fn () => DB::table('subscriptions')->where('id', $sub->id)
            ->update(['cancel_at_period_end' => $cancel, 'cancelled_at' => $cancel ? now() : null, 'updated_at' => now()]));
        $this->audit->record($cancel ? 'subscription.cancel_scheduled' : 'subscription.resumed', ['subscriptions', $sub->id], organizationId: $org->id);

        return $this->overview($owner, $org);
    }

    /** The paid, unused part of the live period, in pesos (never more than was paid). */
    private function unusedCredit(object $live): float
    {
        $paid = (float) $this->db->system('billing.credit', fn () => DB::table('subscription_invoices')->where('subscription_id', $live->id)
            ->where('status', 'paid')->orderByDesc('created_at')->value('amount'));
        $start = CarbonImmutable::parse($live->current_period_start);
        $end = CarbonImmutable::parse($live->current_period_end);
        // Whole unused days (today counts as used), so a quote holds for the rest of the day.
        $total = max(1, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 86400));
        $left = max(0, min($total, intdiv(max(0, $end->getTimestamp() - now()->getTimestamp()), 86400)));

        return round($paid * $left / $total, 2);
    }

    /** Switch to $plan when the live period ends (no charge now). */
    private function scheduleChange(Organization $org, object $live, object $plan, string $cycle): void
    {
        $this->db->system('billing.schedule', function () use ($org, $live, $plan, $cycle) {
            $this->dropScheduled($org->id);
            $start = CarbonImmutable::parse($live->current_period_end);
            DB::table('subscriptions')->insert(['id' => (string) Str::uuid7(), 'organization_id' => $org->id, 'plan_id' => $plan->id, 'billing_cycle' => $cycle,
                'status' => 'incomplete', 'current_period_start' => $start, 'current_period_end' => $cycle === 'yearly' ? $start->addYear() : $start->addMonth(),
                'downgraded_from_plan_id' => $live->plan_id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('subscriptions')->where('id', $live->id)->update(['cancel_at_period_end' => true, 'cancelled_at' => null, 'updated_at' => now()]);
        });
        $this->audit->record('subscription.change_scheduled', ['subscriptions', $live->id], new: ['plan' => $plan->name, 'cycle' => $cycle, 'from' => $live->current_period_end], organizationId: $org->id);
    }

    /**
     * Period over with a switch scheduled: the new plan takes over, with its
     * first invoice due within the grace days (like a renewal).
     */
    private function startScheduled(object $old, int $grace): bool
    {
        $next = $this->db->system('billing.cycle_next', fn () => DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.organization_id', $old->organization_id)->where('s.status', 'incomplete')->whereNotNull('s.downgraded_from_plan_id')
            ->first(['s.*', 'p.name as plan', 'p.price_monthly', 'p.price_yearly']));
        if ($next === null) {
            return false;
        }
        $price = (float) ($next->billing_cycle === 'yearly' ? $next->price_yearly : $next->price_monthly);
        $until = CarbonImmutable::parse($old->current_period_end)->addDays($grace);
        $this->db->system('billing.cycle_switch', function () use ($old, $next, $price, $until) {
            $now = now();
            DB::table('subscriptions')->where('id', $old->id)->update(['status' => 'cancelled', 'cancelled_at' => $now, 'updated_at' => $now]);
            // Same shape as a renewal: "previous period" dates, first invoice for the next one.
            DB::table('subscriptions')->where('id', $next->id)->update(['status' => 'grace_period', 'grace_period_ends_at' => $until,
                'current_period_start' => $old->current_period_start, 'current_period_end' => $old->current_period_end, 'updated_at' => $now]);
            $start = CarbonImmutable::parse($old->current_period_end);
            DB::table('subscription_invoices')->insert(['id' => (string) Str::uuid7(), 'subscription_id' => $next->id, 'amount' => number_format($price, 2, '.', ''),
                'billing_period_start' => $start, 'billing_period_end' => $next->billing_cycle === 'yearly' ? $start->addYear() : $start->addMonth(),
                'status' => 'issued', 'issued_at' => $now, 'created_at' => $now]);
        });
        $this->audit->record('subscription.change_started', ['subscriptions', $next->id], new: ['plan' => $next->plan, 'cycle' => $next->billing_cycle, 'amount' => $price], organizationId: $old->organization_id, actorUserId: null);
        $this->alerts->billingUpdate($old->organization_id, "Your switch to {$next->plan} is ready",
            "Pay {$this->peso($price)} by ".$this->when($until->toIso8601String())." to keep {$next->plan} ({$next->billing_cycle}); after that you move to Free.");

        return true;
    }

    /** @return array<string, mixed>|null the switch waiting for the period end */
    private function scheduled(string $orgId): ?array
    {
        $row = $this->db->system('billing.scheduled', fn () => DB::table('subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.organization_id', $orgId)->where('s.status', 'incomplete')->whereNotNull('s.downgraded_from_plan_id')
            ->first(['s.*', 'p.name as plan', 'p.slug', 'p.price_monthly', 'p.price_yearly']));

        return $row ? ['plan' => $row->plan, 'plan_slug' => $row->slug, 'cycle' => $row->billing_cycle,
            'starts_at' => CarbonImmutable::parse($row->current_period_start)->toIso8601String(),
            'price' => (float) ($row->billing_cycle === 'yearly' ? $row->price_yearly : $row->price_monthly)] : null;
    }

    private function dropScheduled(string $orgId): void
    {
        DB::table('subscriptions')->where('organization_id', $orgId)->where('status', 'incomplete')->whereNotNull('downgraded_from_plan_id')
            ->update(['status' => 'expired', 'updated_at' => now()]);
    }

    /** Unfinished checkouts of this organization are replaced by a new one. */
    private function abandonCheckouts(string $orgId): void
    {
        $stale = DB::table('subscriptions')->where('organization_id', $orgId)->where('status', 'incomplete')->whereNull('downgraded_from_plan_id')->pluck('id');
        DB::table('subscription_invoices')->whereIn('subscription_id', $stale)->where('status', 'issued')->update(['status' => 'void']);
        DB::table('payments')->whereIn('subscription_invoice_id', DB::table('subscription_invoices')->whereIn('subscription_id', $stale)->select('id'))
            ->where('status', PaymentStatus::Pending->value)->update(['status' => PaymentStatus::Cancelled->value, 'failure_reason' => 'replaced by a new checkout', 'updated_at' => now()]);
        DB::table('subscriptions')->whereIn('id', $stale)->update(['status' => 'expired', 'updated_at' => now()]);
    }

    private function paidPlan(string $slug): object
    {
        $plan = $this->db->system('billing.plan', fn () => DB::table('plans')->where('slug', $slug)->where('is_active', true)->whereNull('deleted_at')->first())
            ?? throw new ApiException(ErrorCode::NotFound, 'That plan is not available.', 404);
        if ($plan->is_default_free) {
            throw new ApiException(ErrorCode::BusinessRuleViolation, 'Free needs no checkout. Cancel your paid plan to return to Free at the end of the period.', 409);
        }

        return $plan;
    }

    private function peso(float $amount): string
    {
        return '₱'.number_format($amount, 2);
    }

    private function openInvoice(string $orgId): ?array
    {
        $i = DB::table('subscription_invoices as i')->join('subscriptions as s', 's.id', '=', 'i.subscription_id')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.organization_id', $orgId)->where('s.status', 'grace_period')->where('i.status', 'issued')
            ->orderByDesc('i.created_at')->first(['i.*', 'p.name as plan', 'p.slug', 's.billing_cycle', 's.grace_period_ends_at']);

        return $i ? $this->presentInvoice($i) + ['plan_slug' => $i->slug, 'due_by' => CarbonImmutable::parse($i->grace_period_ends_at)->toIso8601String()] : null;
    }

    private function graceDays(): int
    {
        return max(0, min(30, (int) $this->settings->get('subscription_grace_period_days', 3)));
    }

    private function assertOwner(User $user, Organization $org): void
    {
        if (! $this->access->isOwner($user, $org)) {
            throw new ApiException(ErrorCode::Forbidden, 'Only an owner of the organization can manage the plan and billing.', 403);
        }
    }

    /** @return array<string, mixed> */
    private function presentPlan(object $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'slug' => $p->slug, 'price_monthly' => (float) $p->price_monthly,
            'price_yearly' => (float) $p->price_yearly, 'is_free' => (bool) $p->is_default_free, 'is_active' => (bool) $p->is_active];
    }

    /** @return array<string, mixed> */
    private function presentSubscription(object $s): array
    {
        return [
            'id' => $s->id,
            'status' => $s->status,
            'cycle' => $s->billing_cycle,
            'period_start' => CarbonImmutable::parse($s->current_period_start)->toIso8601String(),
            'period_end' => CarbonImmutable::parse($s->current_period_end)->toIso8601String(),
            'cancel_at_period_end' => (bool) $s->cancel_at_period_end,
            'grace_until' => $s->grace_period_ends_at ? CarbonImmutable::parse($s->grace_period_ends_at)->toIso8601String() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentInvoice(object $i): array
    {
        return [
            'id' => $i->id,
            'plan' => $i->plan,
            'cycle' => $i->billing_cycle,
            'amount' => (float) $i->amount,
            'status' => $i->status,
            'period_start' => CarbonImmutable::parse($i->billing_period_start)->toIso8601String(),
            'period_end' => CarbonImmutable::parse($i->billing_period_end)->toIso8601String(),
            'issued_at' => $i->issued_at ? CarbonImmutable::parse($i->issued_at)->toIso8601String() : null,
        ] + $this->creditOf($i);
    }

    /**
     * An upgrade invoice is the list price minus the credited days; show both.
     *
     * @return array<string, float>
     */
    private function creditOf(object $i): array
    {
        if (! isset($i->price_monthly)) {
            return [];
        }
        $list = (float) ($i->billing_cycle === 'yearly' ? $i->price_yearly : $i->price_monthly);
        $credit = round($list - (float) $i->amount, 2);

        return $credit > 0 ? ['list_price' => $list, 'credit' => $credit] : [];
    }

    private function when(string $iso): string
    {
        return CarbonImmutable::parse($iso)->setTimezone('Asia/Manila')->format('M j, Y');
    }
}
