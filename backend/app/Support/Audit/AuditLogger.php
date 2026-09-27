<?php

namespace App\Support\Audit;

use App\Support\Database\DatabaseContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Business accountability trail (public.audit_logs), separate from the
 * technical application log.
 *
 * Rows are written on the domain connection INSIDE the current transaction,
 * so an audit entry exists if and only if the audited change committed.
 * nasery_app has no INSERT grant on audit_logs (0027), so the write goes
 * through the system context. Domain functions from 0021 write their own
 * audit rows; don't duplicate those.
 */
class AuditLogger
{
    public function __construct(
        private readonly DatabaseContext $context,
        private readonly Request $request,
    ) {}

    /**
     * @param  Model|array{0: string, 1: string|null}  $entity  a model, or [entity_type, entity_id]
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        string $action,
        Model|array $entity,
        ?array $old = null,
        ?array $new = null,
        ?string $organizationId = null,
        ?string $reason = null,
        ?string $actorUserId = null,
    ): void {
        [$entityType, $entityId] = $entity instanceof Model
            ? [$entity->getTable(), (string) $entity->getKey()]
            : $entity;

        $row = [
            'actor_user_id' => $actorUserId ?? $this->context->userId() ?? $this->request->user()?->getAuthIdentifier(),
            'organization_id' => $organizationId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old === null ? null : json_encode(self::scrub($old), JSON_THROW_ON_ERROR),
            'new_values' => $new === null ? null : json_encode(self::scrub($new), JSON_THROW_ON_ERROR),
            'reason' => $reason,
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 500) ?: null,
            'request_id' => $this->request->attributes->get('request_id'),
            // Wall-clock time with microseconds: PostgreSQL now() is fixed for
            // the whole transaction, so rows written in one request would
            // otherwise share a timestamp and lose their order (timelines).
            'created_at' => now()->format('Y-m-d H:i:s.uP'),
        ];

        $this->context->system('audit.write', fn () => $this->context->connection()->table('audit_logs')->insert($row));
    }

    /** Credentials and secrets: never stored in audit rows or logs. */
    public const SECRET_KEYS = '/pass(word)?|secret|token|api[_-]?key|authorization|card|cvc|otp|code_hash/i';

    /** Additionally stripped from technical logs (request headers/cookies). */
    public const LOG_ONLY_KEYS = '/^(cookie|set-cookie|x-xsrf-token|x-csrf-token|_token|.*[_-]session)$/i';

    /**
     * Never persist secrets in audit payloads.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function scrub(array $values, bool $forLogs = false): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && (preg_match(self::SECRET_KEYS, $key) || ($forLogs && preg_match(self::LOG_ONLY_KEYS, $key)))) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = self::scrub($value, $forLogs);
            }
        }

        return $values;
    }
}
