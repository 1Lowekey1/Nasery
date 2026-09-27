<?php

namespace App\Logging;

use App\Support\Audit\AuditLogger;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/**
 * Logging tap: strips credentials/secrets/tokens from log context before
 * anything is written, whichever channel is used.
 */
class RedactSensitiveData
{
    public function __invoke(Logger $logger): void
    {
        $logger->getLogger()->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                context: AuditLogger::scrub($record->context, forLogs: true),
                extra: AuditLogger::scrub($record->extra, forLogs: true),
            );
        });
    }
}
