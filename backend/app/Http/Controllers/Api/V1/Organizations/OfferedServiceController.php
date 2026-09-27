<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\OfferedServiceRequest;
use App\Http\Resources\OfferedServiceResource;
use App\Models\Organization;
use App\Models\OrganizationService;
use App\Services\Organizations\OfferedServices;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class OfferedServiceController extends Controller
{
    public function __construct(private readonly OfferedServices $services) {}

    public function index(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return ApiResponse::success(OfferedServiceResource::collection($this->services->list($organization)));
    }

    public function store(OfferedServiceRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manage', $organization);

        return ApiResponse::created(new OfferedServiceResource($this->services->create($organization, $request->validated())), 'Service added.');
    }

    public function update(OfferedServiceRequest $request, Organization $organization, OrganizationService $service): JsonResponse
    {
        Gate::authorize('manage', $organization);

        return ApiResponse::success(new OfferedServiceResource($this->services->update($service, $request->validated())), 'Service updated.');
    }

    public function destroy(Organization $organization, OrganizationService $service): JsonResponse
    {
        Gate::authorize('manage', $organization);

        $this->services->delete($service);

        return ApiResponse::success(null, 'Service removed.');
    }

    /** The platform catalog organizers can pick from. */
    public function catalog(): JsonResponse
    {
        return ApiResponse::success($this->services->catalog()->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'category' => $s->category,
        ])->values());
    }
}
