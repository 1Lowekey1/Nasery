<?php

namespace App\Http\Controllers\Api\V1\Inquiries;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Organization;
use App\Services\Inquiries\OrganizerInquiries;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inquiries an organization was invited to. Requires the inquiry.manage
 * permission (owners have it) and an operational (approved, not suspended)
 * organization for any response.
 */
class OrganizerInquiryController extends Controller
{
    public function __construct(
        private readonly OrganizerInquiries $inquiries,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeInquiries($request, $organization);

        $data = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(['pending', 'viewed', 'declined', 'quoted', 'withdrawn', 'accepted', 'rejected'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiResponse::success($this->inquiries->list($organization, $data['status'] ?? null, (int) ($data['page'] ?? 1)));
    }

    public function show(Request $request, Organization $organization, Inquiry $inquiry): JsonResponse
    {
        $this->authorizeInquiries($request, $organization);

        return ApiResponse::success($this->inquiries->show($organization, $inquiry));
    }

    public function decline(Request $request, Organization $organization, Inquiry $inquiry): JsonResponse
    {
        $this->authorizeInquiries($request, $organization, respond: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::success($this->inquiries->decline($organization, $inquiry, $data['reason']), 'Inquiry declined.');
    }

    private function authorizeInquiries(Request $request, Organization $organization, bool $respond = false): void
    {
        if (! $this->access->can($request->user(), $organization, 'inquiry.manage')) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        if ($respond && ! $organization->isOperational()) {
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to respond to inquiries.', 403);
        }
    }
}
