<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\UpdateProfileRequest;
use App\Http\Resources\OrganizationProfileResource;
use App\Models\Organization;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OrganizationProfileController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return ApiResponse::success(new OrganizationProfileResource($organization->profile()->firstOrFail()));
    }

    public function update(UpdateProfileRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manage', $organization);

        $profile = $organization->profile()->firstOrFail();
        $data = $request->validated();
        $old = $profile->only(array_keys($data));
        $profile->update($data);

        $this->audit->record('organization.profile_updated', $profile, old: $old, new: $data, organizationId: $organization->id);

        return ApiResponse::success(new OrganizationProfileResource($profile), 'Profile updated.');
    }
}
