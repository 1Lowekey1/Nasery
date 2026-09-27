<?php

namespace App\Http\Controllers\Api\V1\Marketplace;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceDirectory;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, guest-accessible organizer directory (B3).
 */
class MarketplaceController extends Controller
{
    public function __construct(private readonly MarketplaceDirectory $directory) {}

    public function organizers(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'service' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'radius_km' => ['nullable', 'numeric', 'min:1', 'max:'.MarketplaceDirectory::MAX_RADIUS_KM],
            'sort' => ['nullable', 'in:rating,name,distance'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        foreach (['lat', 'lng', 'radius_km'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (float) $filters[$key];
            }
        }
        foreach (['page', 'per_page'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return ApiResponse::success($this->directory->organizers($filters));
    }

    public function organizer(string $slug): JsonResponse
    {
        return ApiResponse::success($this->directory->organizer($slug) ?? $this->notFound());
    }

    public function reviews(Request $request, string $slug): JsonResponse
    {
        $page = (int) ($request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:1000']])['page'] ?? 1);

        return ApiResponse::success($this->directory->reviews($slug, max(1, $page)) ?? $this->notFound());
    }

    public function eventTypes(): JsonResponse
    {
        return ApiResponse::success($this->directory->platformEventTypes());
    }

    public function services(): JsonResponse
    {
        return ApiResponse::success($this->directory->serviceNames());
    }

    private function notFound(): never
    {
        // Unpublished, unapproved and suspended organizers are simply absent.
        throw new ApiException(ErrorCode::NotFound, 'This organizer is not listed on NaSeRy.', 404);
    }
}
