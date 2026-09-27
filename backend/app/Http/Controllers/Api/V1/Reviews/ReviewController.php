<?php

namespace App\Http\Controllers\Api\V1\Reviews;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Organization;
use App\Services\Reviews\ReviewManager;
use App\Support\Authorization\OrganizationAccess;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * B16 reviews.
 *  - client (the booking client of a completed event): view / post / edit / remove their review
 *  - organization members: read all their reviews; owners reply publicly
 *  - anyone signed in: report a review for the admin
 */
class ReviewController extends Controller
{
    public function __construct(
        private readonly ReviewManager $reviews,
        private readonly OrganizationAccess $access,
    ) {}

    // Client ------------------------------------------------------------

    public function mine(Request $request, Event $event): JsonResponse
    {
        return ApiResponse::success($this->reviews->forClient($request->user(), $event));
    }

    public function store(Request $request, Event $event): JsonResponse
    {
        $data = $this->validated($request);

        return ApiResponse::created($this->reviews->create($request->user(), $event, (int) $data['rating'], $data['written_review']), 'Thank you for your review!');
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $data = $this->validated($request);

        return ApiResponse::success($this->reviews->update($request->user(), $event, (int) $data['rating'], $data['written_review']), 'Review updated.');
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $this->reviews->remove($request->user(), $event);

        return ApiResponse::success(null, 'Review removed.');
    }

    // Organizer ---------------------------------------------------------

    public function index(Request $request, Organization $organization): JsonResponse
    {
        $member = $this->access->membership($request->user(), $organization)
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);

        return ApiResponse::success([...$this->reviews->forOrganization($organization), 'can' => ['reply' => (bool) $member->is_owner]]);
    }

    public function reply(Request $request, Organization $organization, string $review): JsonResponse
    {
        $member = $this->owner($request, $organization);
        $data = $request->validate(['text' => ['required', 'string', 'min:2', 'max:1500']]);

        return ApiResponse::success($this->reviews->reply($organization, $member, $review, trim($data['text'])), 'Reply posted.');
    }

    public function removeReply(Request $request, Organization $organization, string $review): JsonResponse
    {
        $this->owner($request, $organization);
        $this->reviews->removeReply($organization, $review);

        return ApiResponse::success(null, 'Reply removed.');
    }

    // Anyone signed in ----------------------------------------------------

    public function report(Request $request, string $review): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        $this->reviews->report($request->user(), $review, trim($data['reason']));

        return ApiResponse::success(null, 'Thanks. An administrator will review it.');
    }

    /** @return array{rating: int, written_review: ?string} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'written_review' => ['nullable', 'string', 'max:2000'],
        ]);

        return ['rating' => (int) $data['rating'], 'written_review' => filled($data['written_review'] ?? null) ? trim($data['written_review']) : null];
    }

    private function owner(Request $request, Organization $organization)
    {
        $member = $this->access->membership($request->user(), $organization);

        if ($member === null) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }
        if (! $member->is_owner) {
            throw new ApiException(ErrorCode::Forbidden, 'Only the organization owner can reply to reviews.', 403);
        }

        return $member;
    }
}
