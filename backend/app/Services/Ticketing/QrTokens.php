<?php

namespace App\Services\Ticketing;

use App\Models\QrTicket;
use Illuminate\Support\Str;

/**
 * QR credentials. qr_tickets stores only sha256(token) (0021: "the raw
 * scannable token is never stored server-side"). The token itself is an
 * HMAC over the QR row, the ticket and its issue time, keyed with the app
 * key, so an authorized viewer (the ticket holder, or organizer staff) can
 * be shown the same QR again without the server ever persisting it.
 * Reissuing (new issued_at) changes the token; the old one stops matching.
 *
 * Format: NSR1.<qr id>.<43-char base64url HMAC>  (fits a small QR code)
 */
class QrTokens
{
    public const PREFIX = 'NSR1';

    public function token(QrTicket $qr): string
    {
        // UTC with microseconds: identical whether issued_at came from PHP or back from the database.
        $issued = $qr->issued_at->toImmutable()->utc()->format('Y-m-d\TH:i:s.u');
        $mac = hash_hmac('sha256', "{$qr->id}|{$qr->ticket_id}|{$issued}", $this->key(), true);

        return self::PREFIX.'.'.$qr->id.'.'.rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * The attributes for a new (or reissued) QR row.
     *
     * @return array{id: string, issued_at: \Carbon\CarbonImmutable}
     */
    public function freshIdentity(): array
    {
        // Microsecond precision is stored, so the token can be re-derived exactly.
        return ['id' => (string) Str::uuid7(), 'issued_at' => now()->toImmutable()];
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        return str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7)) : $key;
    }
}
