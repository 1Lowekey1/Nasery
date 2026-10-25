<?php

namespace App\Services\Tickets;

use App\Jobs\SendTicketEmail;
use App\Services\Notifications\MailGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Queues ticket e-mails (B13). Tickets are grouped by recipient (the
 * attendee's e-mail, else the purchaser's), one e-mail per person with all
 * their tickets. Every attempt is logged in laravel.mail_deliveries, so the
 * guest list can show "Emailed", "Failed" or why nothing was sent.
 */
class TicketMailer
{
    public function __construct(
        private readonly TicketCards $cards,
        private readonly MailGuard $guard,
    ) {}

    /**
     * @param  list<string>  $ticketIds
     * @return array{queued: int, skipped: int}
     */
    public function send(array $ticketIds, string $kind = 'ticket', ?string $sessionId = null): array
    {
        $cards = $this->cards->load($ticketIds)->filter(fn ($c) => $c['token'] !== null);
        $result = ['queued' => 0, 'skipped' => 0];

        foreach ($cards->groupBy(fn ($c) => strtolower(trim((string) $c['recipient']))) as $recipient => $group) {
            $allowed = $this->guard->allows($recipient);
            $id = $this->log($group, $recipient ?: null, $kind, $sessionId, $allowed ? 'queued' : 'suppressed', $allowed ? null : $this->guard->reason($recipient));

            if ($allowed) {
                SendTicketEmail::dispatch($id)->afterCommit();
                $result['queued']++;
            } else {
                $result['skipped']++;
            }
        }

        return $result;
    }

    /**
     * "Send my ticket again" from the buyer: skipped when it was just e-mailed
     * (it goes out automatically on purchase), so a click right after buying
     * doesn't send a duplicate.
     *
     * @return array{queued: int, skipped: int, already_sent?: bool}
     */
    public function resend(string $ticketId): array
    {
        $last = $this->latestFor([$ticketId])[$ticketId] ?? null;
        if ($last && in_array($last['status'], ['queued', 'sent'], true) && now()->diffInSeconds($last['at'], true) < 120) {
            return ['queued' => 0, 'skipped' => 0, 'already_sent' => true];
        }

        return $this->send([$ticketId]);
    }

    /** Message for the result of resend(). */
    public static function resendMessage(array $result): string
    {
        return match (true) {
            ($result['already_sent'] ?? false) => 'Your ticket was e-mailed a moment ago. Check your inbox and Spam folder.',
            $result['queued'] > 0 => 'Ticket sent again.',
            default => 'We can\'t send e-mail to this address.',
        };
    }

    /**
     * Latest e-mail per ticket, for the guest list.
     *
     * @param  list<string>  $ticketIds
     * @return array<string, array{status: string, recipient: ?string, at: string, error: ?string}>
     */
    public function latestFor(array $ticketIds): array
    {
        if ($ticketIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ticketIds), '?'));
        $rows = DB::connection('system')->select(
            "select distinct on (t.ticket_id) t.ticket_id, d.status, d.recipient, coalesce(d.sent_at, d.updated_at) as at, d.last_error
             from mail_deliveries d cross join lateral jsonb_array_elements_text(d.ticket_ids) as t(ticket_id)
             where t.ticket_id in ({$placeholders})
             order by t.ticket_id, d.created_at desc", $ticketIds);

        return collect($rows)->mapWithKeys(fn ($r) => [$r->ticket_id => [
            'status' => $r->status, 'recipient' => $r->recipient, 'at' => (string) $r->at, 'error' => $r->last_error,
        ]])->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $group
     */
    private function log(Collection $group, ?string $recipient, string $kind, ?string $sessionId, string $status, ?string $error): string
    {
        $first = $group->first();
        $id = (string) Str::uuid7();
        $subjectEvent = mb_substr((string) $first['event'], 0, 150);

        DB::connection('system')->table('mail_deliveries')->insert([
            'id' => $id, 'kind' => $kind, 'channel' => 'email', 'recipient' => $recipient,
            'subject' => $kind === 'ticket_reminder' ? "Reminder: {$subjectEvent}" : "Tickets: {$subjectEvent}",
            'event_id' => $first['event_id'], 'organization_id' => $first['organization_id'], 'session_id' => $sessionId,
            'ticket_ids' => json_encode($group->pluck('id')->values()->all()),
            'status' => $status, 'last_error' => $error, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }
}
