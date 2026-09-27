<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AccountService;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = $this->accounts->register($request->validated(), $request);

        return ApiResponse::created(new UserResource($user), 'Account created. We sent a verification code to your email.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->accounts->login($request->string('email'), $request->string('password'), $request);

        return ApiResponse::success(new UserResource($user), 'Signed in.');
    }

    public function logout(Request $request): JsonResponse
    {
        $this->accounts->logout($request);

        return ApiResponse::success(null, 'Signed out.');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(new UserResource($request->user()));
    }

    public function updateMe(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->accounts->updateProfile($request->user(), $request->validated());

        return ApiResponse::success(new UserResource($user), 'Profile updated.');
    }
}
