<?php

namespace App\Exceptions;

use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Converts every exception raised under /api into the standard error
 * envelope. Internal details (SQL, stack traces, constraint names) are only
 * exposed in details.debug when APP_DEBUG is on.
 */
class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => $e->render(),

            $e instanceof ValidationException => ApiResponse::error(
                ErrorCode::ValidationError,
                'The given data was invalid.',
                422,
                ['fields' => $e->errors()],
            ),

            $e instanceof AuthenticationException => ApiResponse::error(
                ErrorCode::Unauthenticated, 'Authentication is required.', 401,
            ),

            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => ApiResponse::error(
                ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403,
            ),

            $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => ApiResponse::error(
                ErrorCode::NotFound, 'The requested resource was not found.', 404,
            ),

            $e instanceof MethodNotAllowedHttpException => ApiResponse::error(
                ErrorCode::MethodNotAllowed, 'This method is not allowed for this endpoint.', 405,
            ),

            $e instanceof TokenMismatchException => self::csrf(),

            $e instanceof ThrottleRequestsException => ApiResponse::error(
                ErrorCode::RateLimited, 'Too many requests. Please slow down.', 429, [], $e->getHeaders(),
            ),

            $e instanceof QueryException => self::query((string) ($e->errorInfo[0] ?? $e->getCode()), $e),

            $e instanceof PDOException => self::query((string) ($e->errorInfo[0] ?? $e->getCode()), $e),

            $e instanceof HttpExceptionInterface => self::http($e),

            default => ApiResponse::error(
                ErrorCode::ServerError, 'Something went wrong on our side.', 500, self::debug($e),
            ),
        };
    }

    /**
     * PostgreSQL errors that reach the API are integrity/security backstops
     * firing (constraints, RLS, triggers, 0021 domain functions). Map them to
     * meaningful statuses without leaking schema internals.
     */
    private static function query(string $state, Throwable $e): JsonResponse
    {
        $debug = self::debug($e);

        return match ($state) {
            '23505' => ApiResponse::error(ErrorCode::Conflict, 'This conflicts with an existing record.', 409, $debug),
            '23503' => ApiResponse::error(ErrorCode::Conflict, 'A related record is missing or still in use.', 409, $debug),
            '23514', '23502', '22P02', '22001', '22003', '22007', '22008' => ApiResponse::error(
                ErrorCode::ValidationError, 'The data violates a database rule.', 422, $debug,
            ),
            '23P01' => ApiResponse::error(ErrorCode::Conflict, 'This overlaps with an existing record.', 409, $debug),
            '42501' => ApiResponse::error(ErrorCode::Forbidden, 'You are not allowed to perform this action.', 403, $debug),
            'P0001' => ApiResponse::error(ErrorCode::BusinessRuleViolation, 'This action is not allowed in the current state.', 409, $debug),
            '40001', '40P01', '55P03' => ApiResponse::error(
                ErrorCode::ConcurrencyConflict, 'The resource was modified concurrently. Please retry.', 409, $debug,
            ),
            '08000', '08001', '08003', '08006', '57P01', '53300' => ApiResponse::error(
                ErrorCode::ServiceUnavailable, 'The service is temporarily unavailable.', 503, $debug,
            ),
            default => ApiResponse::error(ErrorCode::ServerError, 'Something went wrong on our side.', 500, $debug),
        };
    }

    private static function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();

        if ($status === 419) {
            return self::csrf();
        }

        $code = match (true) {
            $status === 400 => ErrorCode::BadRequest,
            $status === 401 => ErrorCode::Unauthenticated,
            $status === 403 => ErrorCode::Forbidden,
            $status === 404 => ErrorCode::NotFound,
            $status === 409 => ErrorCode::Conflict,
            $status === 422 => ErrorCode::ValidationError,
            $status === 429 => ErrorCode::RateLimited,
            $status === 503 => ErrorCode::ServiceUnavailable,
            $status >= 500 => ErrorCode::ServerError,
            default => ErrorCode::BadRequest,
        };

        $message = $status >= 500 || $e->getMessage() === ''
            ? 'The request could not be completed.'
            : $e->getMessage();

        return ApiResponse::error($code, $message, $status, [], $e->getHeaders());
    }

    private static function csrf(): JsonResponse
    {
        return ApiResponse::error(
            ErrorCode::CsrfTokenMismatch,
            'Your session token expired. Refresh the CSRF cookie and retry.',
            419,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function debug(Throwable $e): array
    {
        if (! config('app.debug')) {
            return [];
        }

        return ['debug' => [
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]];
    }
}
