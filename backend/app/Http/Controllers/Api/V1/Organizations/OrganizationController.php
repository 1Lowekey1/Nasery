<?php

namespace App\Http\Controllers\Api\V1\Organizations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizations\CreateOrganizationRequest;
use App\Http\Requests\Organizations\UpdateOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Organizations\OrganizerOnboarding;
use App\Support\Audit\AuditLogger;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizerOnboarding $onboarding,
        private readonly AuditLogger $audit,
    ) {}

    /** Onboarding: create my organization and submit it for approval. */
    public function store(CreateOrganizationRequest $request): JsonResponse
    {
        $organization = $this->onboarding->create($request->user(), $request->validated());

        return ApiResponse::created(
            new OrganizationResource($organization->load('profile')),
            'Organization created and submitted for review.',
        );
    }

    /** Organizations I belong to (RLS returns only my own). */
    public function mine(Request $request): JsonResponse
    {
        $organizations = Organization::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $request->user()->id)->where('status', 'active'))
            ->with('profile')
            ->orderBy('created_at')
            ->get();

        return ApiResponse::success(OrganizationResource::collection($organizations));
    }

    public function show(Organization $organization): JsonResponse
    {
        Gate::authorize('view', $organization);

        return ApiResponse::success(new OrganizationResource($organization->load('profile')));
    }

    public function update(UpdateOrganizationRequest $request, Organization $organization): JsonResponse
    {
        Gate::authorize('manage', $organization);

        $data = $request->validated();

        if (isset($data['slug']) && $data['slug'] !== $organization->slug && $this->onboarding->slugTaken($data['slug'], $organization->id)) {
            throw ValidationException::withMessages(['slug' => 'That address is already taken.']);
        }

        $old = $organization->only(array_keys($data));
        $organization->update($data); // name/slug columns only are app-writable (0028)
        $this->audit->record('organization.updated', $organization, old: $old, new: $data, organizationId: $organization->id);

        return ApiResponse::success(new OrganizationResource($organization->load('profile')), 'Organization updated.');
    }
}
