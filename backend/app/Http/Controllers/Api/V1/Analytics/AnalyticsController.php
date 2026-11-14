<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Analytics\EventTrends;
use App\Services\Analytics\EventTypeBreakdown;
use App\Services\Analytics\LiveEvents;
use App\Services\Analytics\OrganizerAnalytics;
use App\Services\Billing\Entitlements;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * B15 organizer analytics. Each member sees only the sections their role
 * allows:
 *  - bid performance: inquiry.manage or quotation.manage
 *  - event performance: members who see every event (event-wide roles, B8)
 *  - revenue inside it: finance.view
 *  - live events: any member, for the events they can work on
 */
class AnalyticsController extends Controller
{
    public function __construct(
        private readonly OrganizerAnalytics $analytics,
        private readonly LiveEvents $live,
        private readonly OrganizationAccess $access,
        private readonly Entitlements $entitlements,
        private readonly EventTrends $trends,
    ) {}

    public function overview(Request $request, Organization $organization): JsonResponse
    {
        $this->member($request, $organization);
        $data = $request->validate([
            'range' => ['sometimes', Rule::in(['3m', '6m', '12m', 'ytd', '24m'])],
            'group' => ['sometimes', Rule::in(['month', 'quarter'])],
        ]);

        $can = [
            'bids' => $this->access->can($request->user(), $organization, 'inquiry.manage') || $this->access->can($request->user(), $organization, 'quotation.manage'),
            'events' => $this->access->seesAllEvents($request->user(), $organization),
            'revenue' => $this->access->can($request->user(), $organization, 'finance.view'),
        ];

        if (! $can['bids'] && ! $can['events']) {
            throw new ApiException(ErrorCode::Forbidden, 'Analytics are for members who handle bids or manage events. Live events are still available.', 403);
        }

        // B18: Free keeps analytics, with a shorter history; quarterly view is a paid extra.
        $months = $this->entitlements->limit($organization, 'analytics_history_months');
        $span = ['3m' => 3, '6m' => 6, '12m' => 12, 'ytd' => (int) now('Asia/Manila')->month, '24m' => 24];
        $allowed = array_keys(array_filter($span, fn ($m) => $months === null || $m <= $months));
        $range = $data['range'] ?? (in_array('12m', $allowed, true) ? '12m' : end($allowed));
        if (! in_array($range, $allowed, true)) {
            $plan = $this->entitlements->for($organization)['plan']->name;
            throw new ApiException(ErrorCode::PlanLimitReached, "Your {$plan} plan shows the last {$months} months. Upgrade in Plan & Billing for a longer history.", 402,
                ['feature' => 'analytics_history_months', 'limit' => $months, 'plan' => $plan, 'upgrade_url' => '/organizer/billing']);
        }
        if (($data['group'] ?? 'month') === 'quarter') {
            $this->entitlements->assertFeature($organization, 'advanced_reports');
        }
        $period = $this->analytics->period($range, $data['group'] ?? 'month');

        return ApiResponse::success(array_filter([
            'period' => ['from' => $period['from']->toDateString(), 'to' => $period['to']->toDateString(), 'group' => $period['group'], 'labels' => $period['labels']],
            'bids' => $can['bids'] ? $this->analytics->bids($organization, $period) : null,
            'events' => $can['events'] ? $this->analytics->events($organization, $period, $can['revenue'] && $can['events']) : null,
            'can' => $can,
            'plan' => ['ranges' => array_values($allowed), 'quarterly' => $this->entitlements->has($organization, 'advanced_reports'), 'history_months' => $months],
        ], fn ($v) => $v !== null));
    }

    public function live(Request $request, Organization $organization): JsonResponse
    {
        $this->member($request, $organization);
        $user = $request->user();

        return ApiResponse::success($this->live->forOrganization($organization, fn (Event $e) => $this->access->canSeeEvent($user, $e)
            && collect(['checkin.perform', 'event.update', 'attendee.manage'])->contains(fn ($p) => $this->access->canOnEvent($user, $e, $p))));
    }

    /** The event types this organizer handles most (Home), for members who see bids or events. */
    public function eventTypes(Request $request, Organization $organization): JsonResponse
    {
        $this->member($request, $organization);
        $data = $request->validate(['months' => ['sometimes', 'integer', Rule::in([3, 6, 12])]]);
        $user = $request->user();
        if (! $this->access->seesAllEvents($user, $organization) && ! $this->access->can($user, $organization, 'inquiry.manage')
            && ! $this->access->can($user, $organization, 'quotation.manage')) {
            throw new ApiException(ErrorCode::Forbidden, 'This is for members who handle bids or manage events.', 403);
        }

        return ApiResponse::success($this->trends->forOrganization($organization->id, (int) ($data['months'] ?? 6)));
    }

    /** Analytics → Event Types: the detail behind Home's two event-type cards. */
    public function eventTypeBreakdown(Request $request, Organization $organization, EventTypeBreakdown $breakdown): JsonResponse
    {
        $this->member($request, $organization);
        $data = $request->validate(['range' => ['sometimes', Rule::in(['3m', '6m', '12m'])]]);
        $user = $request->user();
        $can = [
            'bids' => $this->access->can($user, $organization, 'inquiry.manage') || $this->access->can($user, $organization, 'quotation.manage'),
            'events' => $this->access->seesAllEvents($user, $organization),
        ];
        if (! $can['bids'] && ! $can['events']) {
            throw new ApiException(ErrorCode::Forbidden, 'Analytics are for members who handle bids or manage events. Live events are still available.', 403);
        }

        // Same plan rule as the overview: Free shows a shorter history.
        $months = $this->entitlements->limit($organization, 'analytics_history_months');
        $allowed = array_keys(array_filter(['3m' => 3, '6m' => 6, '12m' => 12], fn ($m) => $months === null || $m <= $months));
        $range = $data['range'] ?? (in_array('6m', $allowed, true) ? '6m' : end($allowed));
        if (! in_array($range, $allowed, true)) {
            $plan = $this->entitlements->for($organization)['plan']->name;
            throw new ApiException(ErrorCode::PlanLimitReached, "Your {$plan} plan shows the last {$months} months. Upgrade in Plan & Billing for a longer history.", 402,
                ['feature' => 'analytics_history_months', 'limit' => $months, 'plan' => $plan, 'upgrade_url' => '/organizer/billing']);
        }
        $period = $this->analytics->period($range, 'month');

        return ApiResponse::success([
            'period' => ['from' => $period['from']->toDateString(), 'to' => $period['to']->toDateString(), 'keys' => $period['keys'], 'labels' => $period['labels']],
            'ranges' => array_values($allowed),
            'can' => $can,
        ] + $breakdown->forOrganization($organization, $period, $can['bids'], $can['events']));
    }

    private function member(Request $request, Organization $organization): void
    {
        if ($this->access->membership($request->user(), $organization) === null) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
    }
}
