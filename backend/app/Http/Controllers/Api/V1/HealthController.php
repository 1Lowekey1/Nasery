<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        DB::select('select 1');

        return ApiResponse::success([
            'status' => 'ok',
            'api_version' => 'v1',
            'time' => now()->toIso8601String(),
        ]);
    }
}
