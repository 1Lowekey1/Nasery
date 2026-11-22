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

    /**
     * Field test (Nov 2026): once booked, the organizer's team adds pictures to the client's
     * mood board (e.g. the agreed palette) and removes only the ones it added.
     */
    public function addMoodImage(Request $request, Organization $organization, Inquiry $inquiry, \App\Services\Inquiries\MoodBoards $boards): JsonResponse
    {
        $this->authorizeBoard($request, $organization, $inquiry, $boards);
        $request->validate([
            'image' => ['required', 'file', 'max:'.(int) ceil(config('nasery.media.max_bytes') / 1024)],
            'caption' => ['nullable', 'string', 'max:120'],
        ], ['image.required' => 'Choose an image to upload.', 'image.max' => 'Choose an image under 5 MB.']);
        $boards->add($inquiry, $request->file('image'), $request->input('caption'), $organization);

        return ApiResponse::created($this->inquiries->show($organization, $inquiry->refresh()), 'Picture added.');
    }

    public function removeMoodImage(Request $request, Organization $organization, Inquiry $inquiry, string $image, \App\Services\Inquiries\MoodBoards $boards): JsonResponse
    {
        $this->authorizeBoard($request, $organization, $inquiry, $boards);
        if (! $boards->remove($inquiry, $image, $organization)) {
            throw new ApiException(ErrorCode::Forbidden, 'Only pictures your team added can be removed here; the client removes theirs.', 403);
        }

        return ApiResponse::success($this->inquiries->show($organization, $inquiry->refresh()), 'Picture removed.');
    }

    private function authorizeBoard(Request $request, Organization $organization, Inquiry $inquiry, \App\Services\Inquiries\MoodBoards $boards): void
    {
        $this->authorizeInquiries($request, $organization);
        if (! $boards->bookedBy($inquiry, $organization)) {
            throw new ApiException(ErrorCode::InvalidStateTransition, 'Only the organizer booked for this request can add to its mood board, until the event is over.', 409);
        }
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
