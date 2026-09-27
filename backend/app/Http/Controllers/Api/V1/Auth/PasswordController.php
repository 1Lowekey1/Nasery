<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\Auth\AccountService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

class PasswordController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $this->accounts->requestPasswordReset($request->string('email'));

        // Identical answer whether or not the account exists.
        return ApiResponse::success([
            'expires_in_seconds' => config('nasery.auth.codes.ttl_minutes') * 60,
        ], 'If an account exists for that email, we sent a reset code to it.');
    }

    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $this->accounts->resetPassword($request->string('email'), $request->string('code'), $request->string('password'));

        return ApiResponse::success(null, 'Password updated. Sign in with your new password.');
    }

    public function change(ChangePasswordRequest $request): JsonResponse
    {
        $this->accounts->changePassword(
            $request->user(),
            (string) $request->input('current_password', ''),
            $request->string('password'),
            $request,
        );

        return ApiResponse::success(null, 'Password changed. Other devices were signed out.');
    }
}
