<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Admin\AccessRequests;
use App\Services\Admin\PlatformDirectory;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organization's side of NaSeRy admin access (B17): every member can
 * see who asked and who has access; owners approve or refuse requests,
 * invite NaSeRy support, and end access anytime.
 */
class NaseryAccessController extends Controller
{
    public function __construct(
        private readonly AccessRequests $requests,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        return ApiResponse::success($this->requests->forOrganization($organization) + [
            'can' => ['manage' => $this->access->isOwner($request->user(), $organization)],
        ]);
    }

    public function decide(Request $request, Organization $organization, string $accessRequest): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:1000']]);
        $result = $this->requests->decide($request->user(), $organization, $accessRequest, (bool) $data['approve'], $data['note'] ?? null);

        return ApiResponse::success($result, $data['approve'] ? 'Access approved. You can end it anytime.' : 'Request refused.');
    }

    public function invite(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:1000'], 'hours' => ['nullable', 'integer', 'min:1', 'max:72']]);

        return ApiResponse::created($this->requests->invite($request->user(), $organization, trim($data['reason']), $data['hours'] ?? null),
            'NaSeRy support was invited. You will be told when someone accepts.');
    }

    public function cancelInvite(Request $request, Organization $organization, string $accessRequest): JsonResponse
    {
        $this->requests->cancelInvite($request->user(), $organization, $accessRequest);

        return ApiResponse::success(null, 'Invite withdrawn.');
    }

    public function endGrant(Request $request, Organization $organization, string $grant): JsonResponse
    {
        $this->requests->revoke($request->user(), $grant, $organization);

        return ApiResponse::success(null, 'NaSeRy access ended.');
    }

    /** Current platform announcements for whoever is looking (guests too). */
    public function announcements(Request $request, PlatformDirectory $directory): JsonResponse
    {
        return ApiResponse::success($directory->currentAnnouncements($request->user('sanctum')));
    }
}
