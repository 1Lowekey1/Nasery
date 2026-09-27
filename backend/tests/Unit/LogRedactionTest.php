<?php

namespace Tests\Unit;

use App\Support\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;

class LogRedactionTest extends TestCase
{
    public function test_logs_also_strip_cookies_and_session_tokens(): void
    {
        $scrubbed = AuditLogger::scrub([
            'headers' => [
                'cookie' => 'nasery-session=abc',
                'x-xsrf-token' => 'xyz',
                'Set-Cookie' => 'a=b',
                'accept' => 'application/json',
            ],
            'nasery-session' => 'abc',
            'event_session_id' => 'keep-me',
        ], forLogs: true);

        $this->assertSame('[REDACTED]', $scrubbed['headers']['cookie']);
        $this->assertSame('[REDACTED]', $scrubbed['headers']['x-xsrf-token']);
        $this->assertSame('[REDACTED]', $scrubbed['headers']['Set-Cookie']);
        $this->assertSame('application/json', $scrubbed['headers']['accept']);
        $this->assertSame('[REDACTED]', $scrubbed['nasery-session']);
        $this->assertSame('keep-me', $scrubbed['event_session_id']);
    }

    public function test_audit_payloads_keep_domain_fields_named_session(): void
    {
        $scrubbed = AuditLogger::scrub(['event_session_id' => 's1', 'cookie' => 'c']);

        $this->assertSame('s1', $scrubbed['event_session_id']);
        $this->assertSame('c', $scrubbed['cookie']); // only scrubbed for logs
    }
}
