<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Http\ErrorCode;
use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Makes retry-sensitive POSTs (checkout, payment creation, quotation
 * acceptance, ticket holds/transfers, ...) safe to retry.
 *
 * The client sends a unique Idempotency-Key per logical operation. The first
 * request with that key runs; later requests with the same key and the same
 * payload get the stored response replayed; the same key with a different
 * payload, or while the first is still running, is a 409.
 *
 * Usage: ->middleware('idempotent') (key optional) or
 *        ->middleware('idempotent:required').
 *
 * Keys live in laravel.idempotency_keys on the `system` connection, so they
 * survive the business transaction being rolled back. 5xx responses release
 * the key so the client can retry.
 */
class EnsureIdempotency
{
    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $header = (string) config('nasery.idempotency.header', 'Idempotency-Key');
        $key = $request->headers->get($header);

        if ($key === null || $key === '') {
            if ($mode === 'required') {
                throw new ApiException(ErrorCode::IdempotencyKeyRequired, "The {$header} header is required for this operation.", 400);
            }

            return $next($request);
        }

        if (! preg_match('/^[\x21-\x7E]{8,255}$/', $key)) {
            throw new ApiException(ErrorCode::ValidationError, "The {$header} header must be 8-255 visible ASCII characters.", 400);
        }

        $scope = $request->user()?->getAuthIdentifier() ?? 'guest';
        $fingerprint = self::fingerprint($request);

        $record = $this->claim((string) $scope, $key, $request, $fingerprint);

        if ($record !== null) {
            return $this->resolveExisting($record, $fingerprint);
        }

        $response = $next($request);

        $this->finish((string) $scope, $key, $response);

        return $response;
    }

    /**
     * Same operation = same method, path and payload. JSON bodies are
     * canonicalised (object keys sorted) so a client re-serialising the same
     * data in another key order is still recognised as a retry.
     */
    public static function fingerprint(Request $request): string
    {
        $body = $request->getContent();

        if ($request->isJson() && $body !== '') {
            try {
                $body = json_encode(self::sortKeys(json_decode($body, true, 512, JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                // Invalid JSON: fingerprint the raw bytes; validation rejects it anyway.
            }
        }

        return hash('sha256', implode('|', [$request->method(), $request->path(), $body]));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::sortKeys(...), $value);
    }

    /**
     * Try to take ownership of the key. Returns null when this request now
     * owns it, or the existing row when another request already does.
     */
    private function claim(string $scope, string $key, Request $request, string $fingerprint, bool $retried = false): ?object
    {
        $now = now();
        $lockUntil = $now->copy()->addSeconds((int) config('nasery.idempotency.lock_seconds', 60));

        $inserted = $this->table()->insertOrIgnore([
            'scope' => $scope,
            'key' => $key,
            'method' => $request->method(),
            'path' => $request->path(),
            'fingerprint' => $fingerprint,
            'status' => 'processing',
            'locked_until' => $lockUntil,
            'expires_at' => $now->copy()->addHours((int) config('nasery.idempotency.ttl_hours', 24)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return null;
        }

        $existing = $this->table()->where('scope', $scope)->where('key', $key)->first();

        if ($existing === null) {
            // Deleted between our insert and select (released by a 5xx).
            if ($retried) {
                throw new ApiException(ErrorCode::IdempotencyInProgress, 'A request with this idempotency key is still being processed.', 409);
            }

            return $this->claim($scope, $key, $request, $fingerprint, true);
        }

        $expired = now()->greaterThan($existing->expires_at);
        $abandoned = $existing->status === 'processing' && now()->greaterThan($existing->locked_until);

        // An expired key may be reused for anything; an abandoned (crashed)
        // one only by a retry of the same request.
        if ($expired || ($abandoned && $existing->fingerprint === $fingerprint)) {
            // Reclaim atomically: only one retry may win.
            $reclaimed = $this->table()
                ->where('id', $existing->id)
                ->where('updated_at', $existing->updated_at)
                ->update([
                    'method' => $request->method(),
                    'path' => $request->path(),
                    'fingerprint' => $fingerprint,
                    'status' => 'processing',
                    'locked_until' => $lockUntil,
                    'response_status' => null,
                    'response_body' => null,
                    'response_headers' => null,
                    'expires_at' => $now->copy()->addHours((int) config('nasery.idempotency.ttl_hours', 24)),
                    'updated_at' => $now,
                ]);

            if ($reclaimed === 1) {
                return null;
            }

            $existing = $this->table()->where('id', $existing->id)->first() ?? $existing;
        }

        return $existing;
    }

    private function resolveExisting(object $record, string $fingerprint): Response
    {
        if ($record->fingerprint !== $fingerprint) {
            throw new ApiException(
                ErrorCode::IdempotencyKeyReused,
                'This idempotency key was already used with a different request.',
                409,
            );
        }

        if ($record->status !== 'completed') {
            throw new ApiException(
                ErrorCode::IdempotencyInProgress,
                'A request with this idempotency key is still being processed.',
                409,
            );
        }

        $headers = json_decode((string) $record->response_headers, true) ?: [];
        $headers['Idempotency-Replayed'] = 'true';

        return response((string) $record->response_body, (int) $record->response_status, $headers);
    }

    private function finish(string $scope, string $key, Response $response): void
    {
        $query = $this->table()->where('scope', $scope)->where('key', $key);

        // 5xx: let the client retry. Streamed/file bodies can't be stored for
        // replay, so they aren't idempotency-cached either.
        if ($response->getStatusCode() >= 500
            || $response instanceof StreamedResponse
            || $response instanceof BinaryFileResponse) {
            $query->delete();

            return;
        }

        $query->update([
            'status' => 'completed',
            'response_status' => $response->getStatusCode(),
            'response_body' => (string) $response->getContent(),
            'response_headers' => json_encode(array_filter([
                'Content-Type' => $response->headers->get('Content-Type'),
                'Location' => $response->headers->get('Location'),
            ])),
            'updated_at' => now(),
        ]);
    }

    private function table(): Builder
    {
        return $this->connection()->table('idempotency_keys');
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection('system');
    }
}
