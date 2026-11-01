<?php

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Http\Controllers\Controller;
use App\Services\Analytics\EventTrends;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * NaSeRy-wide event trends (counts only) for organizers' Home and the admin
 * overview: which kinds of events clients are asking for, month by month.
 */
class EventTrendsController extends Controller
{
    public function __construct(private readonly EventTrends $trends) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['months' => ['sometimes', 'integer', Rule::in([3, 6, 12])]]);

        return ApiResponse::success($this->trends->monthly((int) ($data['months'] ?? 6)));
    }
}
