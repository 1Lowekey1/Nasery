<?php

namespace App\Services\Billing;

use App\Exceptions\ApiException;
use App\Models\Organization;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * What an organization's plan allows (B18; 0020 plans/features/entitlements,
 * 0041 extra limits). The plan is the live subscription's (active, payment
 * failed or in grace); without one the organization is on the default Free
 * plan: no subscription row is needed for Free.
 *
 * Team rule (2026-09-26): limits BLOCK NEW things with an upgrade prompt;
 * nothing that already exists is deleted or locked after a downgrade.
 * Useful features stay on Free; only extras are limited.
 *
 * Numeric NULL = unlimited (0020 convention).
 */
class Entitlements
{
    /** Human wording for each limit, used in upgrade prompts and the billing page. */
    public const LIMITS = [
        'active_event_limit' => ['label' => 'active events', 'hint' => 'Draft, published and ongoing events.'],
        'total_event_limit' => ['label' => 'events in total', 'hint' => 'All events you have created (completed and cancelled ones too).'],
        'staff_limit' => ['label' => 'team members', 'hint' => 'Invited and active members besides owners.'],
        'custom_event_type_limit' => ['label' => 'custom event types', 'hint' => 'Your own event types on top of NaSeRy\'s standard list.'],
        'price_option_limit' => ['label' => 'price list options', 'hint' => 'Options in your public quotation calculator.'],
        'analytics_history_months' => ['label' => 'months of analytics history', 'hint' => 'How far back your trends go.'],
    ];

    public const FEATURES = [
        'advanced_reports' => 'Quarterly analytics and report CSV exports',
    ];

    public function __construct(private readonly DatabaseContext $db) {}

    /**
     * @return array{plan: object, subscription: ?object, limits: array<string, ?int>, features: array<string, bool>}
     */
    public function for(Organization|string $organization): array
    {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;

        // Not cached: services can outlive a request, and a plan can change mid-flow.
        return $this->db->system('billing.entitlements', function () use ($orgId) {
            $subscription = DB::table('subscriptions')->where('organization_id', $orgId)->whereNull('deleted_at')
                ->whereIn('status', ['active', 'payment_failed', 'grace_period'])->first();
            $plan = $subscription
                ? DB::table('plans')->where('id', $subscription->plan_id)->first()
                : DB::table('plans')->where('is_default_free', true)->whereNull('deleted_at')->first();

            $rows = DB::table('plan_entitlements as e')->join('features as f', 'f.id', '=', 'e.feature_id')
                ->where('e.plan_id', $plan->id)->get(['f.key', 'f.value_type', 'e.boolean_value', 'e.numeric_value']);

            return [
                'plan' => $plan,
                'subscription' => $subscription,
                'limits' => $rows->where('value_type', 'numeric')->mapWithKeys(fn ($r) => [$r->key => $r->numeric_value === null ? null : (int) $r->numeric_value])->all(),
                'features' => $rows->where('value_type', 'boolean')->mapWithKeys(fn ($r) => [$r->key => (bool) $r->boolean_value])->all(),
            ];
        });
    }

    /** Numeric limit, or null for unlimited (a limit the plan doesn't list is unlimited). */
    public function limit(Organization|string $organization, string $key): ?int
    {
        return $this->for($organization)['limits'][$key] ?? null;
    }

    public function has(Organization|string $organization, string $feature): bool
    {
        return $this->for($organization)['features'][$feature] ?? false;
    }

    /** Refuse creating one more of $key when the plan's limit is already used up. */
    public function assertRoom(Organization|string $organization, string $key): void
    {
        $limit = $this->limit($organization, $key);
        if ($limit === null) {
            return;
        }
        $used = $this->usage($organization)[$key] ?? 0;
        if ($used >= $limit) {
            $plan = $this->for($organization)['plan']->name;
            $what = self::LIMITS[$key]['label'] ?? $key;
            throw new ApiException(ErrorCode::PlanLimitReached,
                "Your {$plan} plan allows {$limit} {$what} and you have {$used}. Upgrade in Plan & Billing to add more.", 402,
                ['feature' => $key, 'limit' => $limit, 'used' => $used, 'plan' => $plan, 'upgrade_url' => '/organizer/billing']);
        }
    }

    public function assertFeature(Organization|string $organization, string $feature): void
    {
        if (! $this->has($organization, $feature)) {
            $plan = $this->for($organization)['plan']->name;
            throw new ApiException(ErrorCode::PlanLimitReached,
                (self::FEATURES[$feature] ?? $feature)." are part of the Starter and Pro plans (you're on {$plan}). Upgrade in Plan & Billing.", 402,
                ['feature' => $feature, 'plan' => $plan, 'upgrade_url' => '/organizer/billing']);
        }
    }

    /** @return array<string, int> what the organization uses now, per limit */
    public function usage(Organization|string $organization): array
    {
        $orgId = $organization instanceof Organization ? $organization->id : $organization;

        return $this->db->system('billing.usage', fn () => [
            'active_event_limit' => DB::table('events')->where('organization_id', $orgId)->whereNull('deleted_at')->whereIn('status', ['draft', 'published', 'ongoing'])->count(),
            'total_event_limit' => DB::table('events')->where('organization_id', $orgId)->whereNull('deleted_at')->count(),
            'staff_limit' => DB::table('organization_members')->where('organization_id', $orgId)->whereNull('deleted_at')
                ->where('is_owner', false)->whereIn('status', ['active', 'invited'])->count(),
            'custom_event_type_limit' => DB::table('event_types')->where('organization_id', $orgId)->whereNull('deleted_at')->count(),
            'price_option_limit' => DB::table('organization_price_options')->where('organization_id', $orgId)->whereNull('deleted_at')->count(),
        ]);
    }
}
