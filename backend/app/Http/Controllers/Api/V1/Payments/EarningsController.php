<?php

namespace App\Http\Controllers\Api\V1\Payments;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Billing\Entitlements;
use App\Services\Payments\Earnings;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Where the organizer's ticket money goes, and NaSeRy's payouts of their
 * share (0043). The breakdown is free; per-event and monthly detail and the
 * CSV need a plan with "advanced_reports". Admins record and void payouts.
 */
class EarningsController extends Controller
{
    public function __construct(
        private readonly Earnings $earnings,
        private readonly OrganizationAccess $access,
        private readonly Entitlements $entitlements,
    ) {}

    public function summary(Request $request, Organization $organization): JsonResponse
    {
        $this->canSee($request, $organization);

        return ApiResponse::success([
            ...$this->earnings->summary($organization->id),
            'plan' => ['details' => $this->entitlements->has($organization, 'advanced_reports')],
        ]);
    }

    public function details(Request $request, Organization $organization): JsonResponse
    {
        $this->canSee($request, $organization);
        $this->entitlements->assertFeature($organization, 'advanced_reports');
        $months = min((int) ($request->validate(['months' => ['nullable', 'integer', 'min:1', 'max:24']])['months'] ?? 6),
            $this->entitlements->limit($organization, 'analytics_history_months') ?? 24);

        return ApiResponse::success($this->earnings->details($organization->id, max(1, $months)));
    }

    public function export(Request $request, Organization $organization): Response
    {
        $this->canSee($request, $organization);
        $this->entitlements->assertFeature($organization, 'advanced_reports');

        return response($this->earnings->csv($organization->id), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="earnings-'.$organization->slug.'-'.now('Asia/Manila')->format('Y-m-d').'.csv"',
        ]);
    }

    // ---- Admins ------------------------------------------------------------

    public function balances(): JsonResponse
    {
        return ApiResponse::success(['organizers' => $this->earnings->balances(), 'methods' => Earnings::METHODS]);
    }

    public function payouts(Organization $organization): JsonResponse
    {
        return ApiResponse::success($this->earnings->payoutsFor($organization->id));
    }

    public function record(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1', 'decimal:0,2'],
            'method' => ['required', Rule::in(array_keys(Earnings::METHODS))],
            'reference' => ['required', 'string', 'min:3', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date'],
        ]);

        return ApiResponse::created($this->earnings->record($request->user(), $organization, $data), 'Payout recorded. The organizer was notified.');
    }

    public function void(Request $request, string $payout): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        return ApiResponse::success($this->earnings->void($request->user(), $payout, $data['reason']), 'Payout voided.');
    }

    private function canSee(Request $request, Organization $organization): void
    {
        if (! $this->access->can($request->user(), $organization, 'finance.view')) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to view payments.', 403);
        }
    }
}
