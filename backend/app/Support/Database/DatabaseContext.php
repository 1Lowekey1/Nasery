<?php

namespace App\Support\Database;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use LogicException;
use Throwable;

/**
 * Controls which PostgreSQL role and identity the domain connection uses.
 *
 * Inside an active context the connection runs as the NOLOGIN role
 * `nasery_app` with request.jwt.claims.sub = the caller's users.id, so RLS
 * policies and auth.uid()-based functions see the real caller. Everything is
 * SET LOCAL, so it can never outlive the transaction that owns it.
 *
 * system() temporarily drops back to the login role (BYPASSRLS) for the
 * operations RLS deliberately does not model (sign-up, guest checkout,
 * webhooks, audit writes, marketplace reads). Every call must state why, and
 * is logged, so privileged paths stay few and reviewable.
 */
class DatabaseContext
{
    private bool $active = false;

    private ?string $userId = null;

    private int $systemDepth = 0;

    public function __construct(private readonly DatabaseManager $db) {}

    public function connection(): ConnectionInterface
    {
        return $this->db->connection('pgsql');
    }

    public function enabled(): bool
    {
        return (bool) config('nasery.db.rls_enabled', true);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function inSystemMode(): bool
    {
        return $this->systemDepth > 0;
    }

    public function userId(): ?string
    {
        return $this->userId;
    }

    /**
     * Switch the current transaction into the app role for the given user
     * (or an anonymous caller when null). Must be called inside a transaction.
     */
    public function enter(?string $userId): void
    {
        if ($this->connection()->transactionLevel() === 0) {
            throw new LogicException('DatabaseContext::enter() requires an open transaction.');
        }

        $this->userId = $userId;
        $this->active = true;

        // Role + identity in a single round trip.
        $this->connection()->unprepared(
            ($this->enabled() ? 'SET LOCAL ROLE '.$this->role().'; ' : '').$this->claimsSql($userId),
        );
    }

    /**
     * Restore the login role and clear the identity before COMMIT (a nested
     * transaction's savepoint release would otherwise keep the SET LOCALs).
     * Safe to call repeatedly.
     */
    public function leave(): void
    {
        if (! $this->active) {
            return;
        }

        $connection = $this->connection();

        // A top-level COMMIT/ROLLBACK already reverts every SET LOCAL; only a
        // nested transaction (savepoint, e.g. under tests) needs an explicit
        // reset before its release.
        if ($connection->transactionLevel() > 1) {
            $connection->unprepared('SET LOCAL ROLE NONE; '.$this->claimsSql(null));
        }

        $this->forget();
    }

    /**
     * Clear local state without touching the database. Use after ROLLBACK
     * (or ROLLBACK TO SAVEPOINT), which already reverts every SET LOCAL and
     * is the only thing PostgreSQL accepts in an aborted transaction.
     */
    public function forget(): void
    {
        $this->active = false;
        $this->userId = null;
        $this->systemDepth = 0;
    }

    /**
     * Run $callback with RLS bypassed. Outside a request context (console,
     * queue workers) the connection already runs as the login role, so this
     * only records the reason.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function system(string $reason, Closure $callback): mixed
    {
        Log::debug('db.system_context', ['reason' => $reason, 'user_id' => $this->userId]);

        if (! $this->active || ! $this->enabled()) {
            return $callback();
        }

        $this->systemDepth++;
        $this->connection()->statement('SET LOCAL ROLE NONE');

        try {
            $result = $callback();
        } catch (Throwable $e) {
            $this->systemDepth--;

            // Never stay elevated if the caller catches $e and carries on. If
            // the transaction is already aborted this fails too, which is fine:
            // nothing more can run in it before the rollback.
            try {
                $this->restoreRole();
            } catch (Throwable) {
            }

            throw $e;
        }

        $this->systemDepth--;
        $this->restoreRole();

        return $result;
    }

    /**
     * Run $callback as another user (e.g. a queued job acting on someone's
     * behalf) inside its own transaction.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function asUser(?string $userId, Closure $callback): mixed
    {
        if ($this->active) {
            throw new LogicException('Nested DatabaseContext::asUser() is not supported.');
        }

        return $this->connection()->transaction(function () use ($userId, $callback) {
            $this->enter($userId);

            try {
                $result = $callback();
            } catch (Throwable $e) {
                $this->forget(); // transaction() rolls back, reverting SET LOCAL

                throw $e;
            }

            $this->leave();

            return $result;
        });
    }

    private function restoreRole(): void
    {
        if ($this->active && $this->connection()->transactionLevel() > 0) {
            $this->applyRole();
        }
    }

    private function applyRole(): void
    {
        if ($this->systemDepth > 0 || ! $this->enabled()) {
            return;
        }

        $this->connection()->statement('SET LOCAL ROLE '.$this->role());
    }

    private function role(): string
    {
        $role = (string) config('nasery.db.app_role');

        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $role)) {
            throw new LogicException('Invalid nasery.db.app_role.');
        }

        return $role;
    }

    /**
     * auth.uid() reads request.jwt.claim.sub, then request.jwt.claims->>'sub'.
     * Values are quoted by PDO; the user id is additionally uuid-validated.
     */
    private function claimsSql(?string $userId): string
    {
        if ($userId !== null && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $userId)) {
            throw new LogicException('DatabaseContext user id must be a uuid.');
        }

        $claims = $userId === null
            ? ''
            : json_encode(['sub' => $userId, 'role' => config('nasery.db.app_role')], JSON_THROW_ON_ERROR);

        $pdo = $this->connection()->getPdo();

        return sprintf(
            "SELECT set_config('request.jwt.claims', %s, true), set_config('request.jwt.claim.sub', %s, true)",
            $pdo->quote($claims),
            $pdo->quote($userId ?? ''),
        );
    }
}
