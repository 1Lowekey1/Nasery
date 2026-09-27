<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrganizationResource;
use App\Services\Staff\StaffManager;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in business user's own team invitations (B8).
 */
class InvitationController extends Controller
{
    public function __construct(private readonly StaffManager $staff) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->staff->invitationsFor($request->user()));
    }

    public function accept(Request $request, string $member): JsonResponse
    {
        $organization = $this->staff->respond($request->user(), $member, accept: true);

        return ApiResponse::success(new OrganizationResource($organization->load('profile')), "You joined {$organization->display_name}.");
    }

    public function decline(Request $request, string $member): JsonResponse
    {
        $this->staff->respond($request->user(), $member, accept: false);

        return ApiResponse::success(null, 'Invitation declined.');
    }
}
