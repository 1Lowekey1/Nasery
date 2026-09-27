<?php

namespace App\Http\Middleware;

use App\Support\Database\DatabaseContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Wraps the request in one transaction on the domain connection, running as
 * the RLS-bound app role with the authenticated user's id as auth.uid().
 *
 * Any error response (status >= 400) rolls the transaction back, so a failed
 * request never leaves partial domain writes behind. Infrastructure writes
 * (cache, jobs, sessions, idempotency keys) use the separate `system`
 * connection and are unaffected; jobs are dispatched after commit.
 */
class ApplyDatabaseContext
{
    public function __construct(private readonly DatabaseContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Resolve the user BEFORE switching roles: the session lookup of the
        // users row runs as the login role.
        $userId = $request->user()?->getAuthIdentifier();

        $connection = $this->context->connection();
        $connection->beginTransaction();

        try {
            $this->context->enter($userId === null ? null : (string) $userId);

            $response = $next($request);
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }

        if (($response->exception ?? null) !== null || $response->getStatusCode() >= 400) {
            // The transaction may already be aborted by a failed statement;
            // rollback is the only valid command and reverts the role too.
            $this->rollBack();
        } else {
            $this->context->leave();
            $connection->commit();
        }

        return $this->guardStreamedBody($response, $userId === null ? null : (string) $userId);
    }

    /**
     * A streamed body (CSV/PDF exports, ...) is produced AFTER this middleware
     * has committed and restored the login role, which would run its queries
     * with RLS bypassed. Re-run the callback inside its own RLS-scoped
     * transaction for the same user.
     */
    private function guardStreamedBody(Response $response, ?string $userId): Response
    {
        if ($response instanceof StreamedResponse && ($callback = $response->getCallback()) !== null) {
            $context = $this->context;
            $response->setCallback(static fn () => $context->asUser($userId, $callback));
        }

        return $response;
    }

    private function rollBack(): void
    {
        $this->context->connection()->rollBack();
        $this->context->forget();
    }
}
