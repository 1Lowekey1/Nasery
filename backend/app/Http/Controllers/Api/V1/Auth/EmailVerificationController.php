<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\CodeRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AccountService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function send(Request $request): JsonResponse
    {
        $this->accounts->sendVerificationCode($request->user());

        return ApiResponse::success([
            'expires_in_seconds' => config('nasery.auth.codes.ttl_minutes') * 60,
            'resend_in_seconds' => config('nasery.auth.codes.resend_cooldown_seconds'),
        ], 'We sent a new verification code to your email.');
    }

    public function verify(CodeRequest $request): JsonResponse
    {
        $user = $this->accounts->verifyEmail($request->user(), $request->string('code'));

        return ApiResponse::success(new UserResource($user), 'Email address verified.');
    }
}
