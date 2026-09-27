<?php

namespace App\Http\Controllers\Api\V1\Billing;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Billing\Billing;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * B18 organizer plan & billing. Members see the plan and usage; owners
 * check out, cancel and resume. Admins manage plans and see subscriptions.
 */
class BillingController extends Controller
{
    public function __construct(private readonly Billing $billing) {}

    public function show(Request $request, Organization $organization): JsonResponse
    {
        return ApiResponse::success($this->billing->overview($request->user(), $organization));
    }

    public function quote(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string', 'max:40'], 'cycle' => ['required', Rule::in(['monthly', 'yearly'])]]);

        return ApiResponse::success($this->billing->quote($organization, $data['plan'], $data['cycle']));
    }

    public function checkout(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['plan' => ['required', 'string', 'max:40'], 'cycle' => ['required', Rule::in(['monthly', 'yearly'])]]);
        $result = $this->billing->checkout($request->user(), $organization, $data['plan'], $data['cycle']);

        return $result['scheduled']
            ? ApiResponse::success($result, $result['quote']['message'])
            : ApiResponse::created($result, $result['checkout_url'] ? 'Continue to payment.' : 'Your new plan is active.');
    }

    public function cancel(Request $request, Organization $organization): JsonResponse
    {
        return ApiResponse::success($this->billing->cancel($request->user(), $organization), 'Your plan will end at the close of this period. Everything you made stays.');
    }

    public function resume(Request $request, Organization $organization): JsonResponse
    {
        return ApiResponse::success($this->billing->resume($request->user(), $organization), 'Your plan will renew as usual.');
    }

    // Admin ---------------------------------------------------------------

    public function admin(): JsonResponse
    {
        return ApiResponse::success($this->billing->adminOverview());
    }

    public function updatePlan(Request $request, string $plan): JsonResponse
    {
        $data = $request->validate([
            'price_monthly' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'price_yearly' => ['sometimes', 'numeric', 'min:0', 'max:10000000'],
            'is_active' => ['sometimes', 'boolean'],
            'limits' => ['sometimes', 'array'],
            'limits.*' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['boolean'],
        ]);

        return ApiResponse::success($this->billing->updatePlan($plan, $data, $request->user()), 'Plan saved. Renewals use the new price.');
    }
}
