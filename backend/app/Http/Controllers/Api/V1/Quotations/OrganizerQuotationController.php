<?php

namespace App\Http\Controllers\Api\V1\Quotations;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Quotations\QuotationRequest;
use App\Models\Inquiry;
use App\Models\Organization;
use App\Models\Quotation;
use App\Services\Quotations\OrganizerQuotations;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * An organization's quotations ("My Offers"). Requires quotation.manage;
 * any write also requires an operational (approved, not suspended)
 * organization.
 */
class OrganizerQuotationController extends Controller
{
    public function __construct(
        private readonly OrganizerQuotations $quotations,
        private readonly OrganizationAccess $access,
    ) {}

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $this->authorizeQuotations($request, $organization);

        $data = $request->validate([
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(['draft', 'submitted', 'accepted', 'rejected', 'withdrawn', 'cancelled'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return ApiResponse::success($this->quotations->list($organization, $data['status'] ?? null, (int) ($data['page'] ?? 1)));
    }

    public function show(Request $request, Organization $organization, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotations($request, $organization, $quotation);

        return ApiResponse::success($this->quotations->show($quotation));
    }

    public function store(QuotationRequest $request, Organization $organization, Inquiry $inquiry): JsonResponse
    {
        $this->authorizeQuotations($request, $organization, write: true);
        $submit = $request->boolean('submit');
        $quotation = $this->quotations->create($organization, $inquiry, $request->validated(), $submit);

        return ApiResponse::success($this->quotations->show($quotation), $submit ? 'Quotation sent.' : 'Draft saved.', 201);
    }

    public function update(QuotationRequest $request, Organization $organization, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotations($request, $organization, $quotation, write: true);
        $quotation = $this->quotations->updateDraft($organization, $quotation, $request->validated());

        return ApiResponse::success($this->quotations->show($quotation), 'Draft saved.');
    }

    public function submit(Request $request, Organization $organization, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotations($request, $organization, $quotation, write: true);

        return ApiResponse::success($this->quotations->show($this->quotations->submit($organization, $quotation)), 'Quotation sent.');
    }

    public function revise(QuotationRequest $request, Organization $organization, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotations($request, $organization, $quotation, write: true);
        $revision = $this->quotations->revise($organization, $quotation, $request->validated());

        return ApiResponse::success($this->quotations->show($revision), 'Revised quotation sent.', 201);
    }

    public function withdraw(Request $request, Organization $organization, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotations($request, $organization, $quotation, write: true);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::success($this->quotations->show($this->quotations->withdraw($organization, $quotation, $data['reason'])), 'Quotation withdrawn.');
    }

    public function destroy(Request $request, Organization $organization, Quotation $quotation): JsonResponse
    {
        $this->authorizeQuotations($request, $organization, $quotation, write: true);
        $this->quotations->deleteDraft($organization, $quotation);

        return ApiResponse::success(null, 'Draft deleted.');
    }

    private function authorizeQuotations(Request $request, Organization $organization, ?Quotation $quotation = null, bool $write = false): void
    {
        if (! $this->access->can($request->user(), $organization, 'quotation.manage')) {
            throw new ApiException(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403);
        }

        // RLS already limits {quotation} to the member's organizations; it
        // must also belong to the organization in the URL.
        if ($quotation !== null && $quotation->organization_id !== $organization->id) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        if ($write && ! $organization->isOperational()) {
            throw new ApiException(ErrorCode::OrganizationNotOperational, 'Your organization must be approved (and not suspended) to send quotations.', 403);
        }
    }
}
