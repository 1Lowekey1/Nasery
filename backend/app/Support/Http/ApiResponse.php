<?php

namespace App\Support\Http;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use JsonSerializable;

/**
 * The one response envelope for /api/v1:
 *
 *   { "success": true,  "data": ..., "message": "...", ["meta": {...}] }
 *   { "success": false, "error": { "code": "...", "message": "...", "details": {...} } }
 */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        [$data, $meta] = self::normalize($data, $meta);

        $payload = [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return new JsonResponse($payload, $status);
    }

    public static function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function error(ErrorCode $code, string $message, int $status, array $details = [], array $headers = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => [
                'code' => $code->value,
                'message' => $message,
                'details' => (object) $details,
            ],
        ], $status, $headers);
    }

    /**
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    private static function normalize(mixed $data, array $meta): array
    {
        if ($data instanceof ResourceCollection && $data->resource instanceof AbstractPaginator) {
            $meta = array_merge(self::paginationMeta($data->resource), $meta);
            $data = $data->collection->map->resolve(request())->all();
        } elseif ($data instanceof AbstractPaginator) {
            $meta = array_merge(self::paginationMeta($data), $meta);
            $data = $data->items();
        } elseif ($data instanceof JsonResource) {
            $data = $data->resolve(request());
        } elseif ($data instanceof Arrayable) {
            $data = $data->toArray();
        } elseif ($data instanceof JsonSerializable) {
            $data = $data->jsonSerialize();
        }

        return [$data, $meta];
    }

    /**
     * @return array<string, mixed>
     */
    private static function paginationMeta(AbstractPaginator $paginator): array
    {
        $meta = [
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
        ];

        if ($paginator instanceof LengthAwarePaginator) {
            $meta['total'] = $paginator->total();
            $meta['last_page'] = $paginator->lastPage();
        }

        return ['pagination' => $meta];
    }
}
