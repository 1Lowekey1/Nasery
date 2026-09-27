<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Auth\SessionService;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function __construct(private readonly SessionService $sessions) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->sessions->list($request->user(), $request->session()->getId()));
    }

    public function destroy(Request $request, string $session): JsonResponse
    {
        if (hash_equals(SessionService::publicId($request->session()->getId()), $session)) {
            throw new ApiException(ErrorCode::BadRequest, 'Use sign out to end the current session.', 400);
        }

        if (! $this->sessions->revoke($request->user(), $session)) {
            throw new ApiException(ErrorCode::NotFound, 'Session not found.', 404);
        }

        return ApiResponse::success(null, 'Device signed out.');
    }

    public function destroyOthers(Request $request): JsonResponse
    {
        $count = $this->sessions->revokeOthers($request->user(), $request->session()->getId());

        return ApiResponse::success(['signed_out' => $count], 'Other devices signed out.');
    }
}
