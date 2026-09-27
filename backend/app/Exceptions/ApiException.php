<?php

namespace App\Exceptions;

use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Throw from actions/services to return a specific API error, e.g.
 * throw new ApiException(ErrorCode::CapacityExceeded, 'Sold out.', 409);
 */
class ApiException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly ErrorCode $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->errorCode, $this->getMessage(), $this->status, $this->details);
    }
}
