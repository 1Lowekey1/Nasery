<?php

namespace App\Http\Controllers\Api\V1\Inquiries;

use App\Http\Controllers\Controller;
use App\Models\Inquiry;
use App\Models\Organization;
use App\Services\Inquiries\BookingCancellations;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use App\Exceptions\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Cancelling an accepted booking (0060). The client reaches their own
 * inquiries under RLS; on the organizer's side only the owner of the
 * organization holding the accepted offer may act.
 */
class BookingCancellationController extends Controller
{
    public function __construct(
        private readonly BookingCancellations $cancellations,
        private readonly DatabaseContext $db,
    ) {}

    public function clientShow(Inquiry $inquiry): JsonResponse
    {
        return ApiResponse::success($this->cancellations->state($inquiry, 'client'));
    }

    public function clientRequest(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->cancellations->request($inquiry, $request->user(), 'client', $this->reason($request));

        return ApiResponse::success($this->cancellations->state($inquiry, 'client'), 'Cancellation requested.');
    }

    public function clientRespond(Request $request, Inquiry $inquiry): JsonResponse
    {
        [$agree, $note] = $this->answer($request);
        $this->cancellations->respond($inquiry, $request->user(), 'client', $agree, $note);

        return ApiResponse::success($this->cancellations->state($inquiry, 'client'));
    }

    public function clientWithdraw(Request $request, Inquiry $inquiry): JsonResponse
    {
        $this->cancellations->withdraw($inquiry, $request->user(), 'client');

        return ApiResponse::success($this->cancellations->state($inquiry, 'client'));
    }

    public function organizerShow(Organization $organization, string $inquiry): JsonResponse
    {
        return ApiResponse::success($this->cancellations->state($this->booking($organization, $inquiry), 'organizer'));
    }

    public function organizerRequest(Request $request, Organization $organization, string $inquiry): JsonResponse
    {
        $booking = $this->booking($organization, $inquiry);
        $this->cancellations->request($booking, $request->user(), 'organizer', $this->reason($request));

        return ApiResponse::success($this->cancellations->state($booking, 'organizer'), 'Cancellation requested.');
    }

    public function organizerRespond(Request $request, Organization $organization, string $inquiry): JsonResponse
    {
        $booking = $this->booking($organization, $inquiry);
        [$agree, $note] = $this->answer($request);
        $this->cancellations->respond($booking, $request->user(), 'organizer', $agree, $note);

        return ApiResponse::success($this->cancellations->state($booking, 'organizer'));
    }

    public function organizerWithdraw(Request $request, Organization $organization, string $inquiry): JsonResponse
    {
        $booking = $this->booking($organization, $inquiry);
        $this->cancellations->withdraw($booking, $request->user(), 'organizer');

        return ApiResponse::success($this->cancellations->state($booking, 'organizer'));
    }

    private function booking(Organization $organization, string $inquiry): Inquiry
    {
        Gate::authorize('manage', $organization);
        $booking = $this->db->system('booking.find', fn () => Inquiry::query()->find($inquiry))
            ?? throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);

        if ($this->cancellations->bookedOrganization($booking) !== $organization->id) {
            throw new ApiException(ErrorCode::NotFound, 'The requested resource was not found.', 404);
        }

        return $booking;
    }

    private function reason(Request $request): string
    {
        return $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']])['reason'];
    }

    /** @return array{0: bool, 1: ?string} */
    private function answer(Request $request): array
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['agree', 'decline'])],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        return [$data['decision'] === 'agree', $data['note'] ?? null];
    }
}
