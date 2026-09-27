<?php

namespace Tests\Unit;

use App\Support\Audit\AuditLogger;
use PHPUnit\Framework\TestCase;

class AuditScrubTest extends TestCase
{
    public function test_secrets_are_redacted_recursively(): void
    {
        $scrubbed = AuditLogger::scrub([
            'email' => 'a@b.test',
            'password' => 'hunter2',
            'password_hash' => '$2y$...',
            'nested' => ['api_key' => 'k', 'amount' => 100, 'Authorization' => 'Bearer x'],
            'paymongo_secret' => 's',
        ]);

        $this->assertSame('a@b.test', $scrubbed['email']);
        $this->assertSame('[REDACTED]', $scrubbed['password']);
        $this->assertSame('[REDACTED]', $scrubbed['password_hash']);
        $this->assertSame('[REDACTED]', $scrubbed['nested']['api_key']);
        $this->assertSame('[REDACTED]', $scrubbed['nested']['Authorization']);
        $this->assertSame(100, $scrubbed['nested']['amount']);
        $this->assertSame('[REDACTED]', $scrubbed['paymongo_secret']);
    }
}
