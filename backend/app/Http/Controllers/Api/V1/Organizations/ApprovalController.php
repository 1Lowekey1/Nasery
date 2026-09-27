<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Resources\ApprovalHistoryResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Organizations\ApprovalWorkflow;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The organizer's view of their own approval.
 */
class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalWorkflow $workflow) {}

    public function show(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return ApiResponse::success([
            'approval_status' => $organization->approval_status->value,
            'is_suspended' => $organization->is_suspended,
            'is_operational' => $organization->isOperational(),
            'can_resubmit' => $organization->approval_status->value === 'rejected',
            'history' => ApprovalHistoryResource::collection($organization->approvalRequests()->get()),
        ]);
    }

    public function resubmit(Request $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manage', $organization);

        $organization = $this->workflow->resubmit($request->user(), $organization);

        return ApiResponse::success(new OrganizationResource($organization->load('profile')), 'Application resubmitted for review.');
    }
}
