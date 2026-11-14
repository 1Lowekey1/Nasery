<?php

namespace App\Http\Controllers\Api\V1\Inquiries;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Inquiries\InquiryThreads;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Client–organizer threads on inquiries (0042). The service decides who is
 * a participant; everyone else gets 404.
 */
class ThreadController extends Controller
{
    public function __construct(private readonly InquiryThreads $threads) {}

    public function show(Request $request, string $thread): JsonResponse
    {
        return ApiResponse::success($this->threads->show($request->user(), $thread));
    }

    public function send(Request $request, string $thread): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.InquiryThreads::MAX_LENGTH]]);

        return ApiResponse::created($this->threads->send($request->user(), $thread, $data['body']));
    }

    public function mine(Request $request): JsonResponse
    {
        return ApiResponse::success($this->threads->forClient($request->user()));
    }

    public function forInquiry(Request $request, string $inquiryId): JsonResponse
    {
        return ApiResponse::success($this->threads->forInquiry($request->user(), $inquiryId));
    }

    public function forOrganization(Request $request, Organization $organization): JsonResponse
    {
        $data = $request->validate(['inquiry' => ['nullable', 'uuid']]);

        return ApiResponse::success($this->threads->forOrganization($request->user(), $organization, $data['inquiry'] ?? null));
    }
}
