<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Mail\NotificationMail;
use App\Services\Notifications\MailGuard;
use App\Support\Database\DatabaseContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one notification e-mail (B13) and records the outcome in
 * notification_deliveries: sent, failed (with the error, after 3 tries),
 * or failed "suppressed" when MailGuard blocks the address.
 */
class DeliverNotificationEmail implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $deliveryId) {}

    public function handle(DatabaseContext $db, MailGuard $guard): void
    {
        $row = $db->system('notify.deliver', fn () => DB::table('notification_deliveries as d')
            ->join('notifications as n', 'n.id', '=', 'd.notification_id')
            ->join('users as u', 'u.id', '=', 'n.recipient_user_id')
            ->where('d.id', $this->deliveryId)
            ->first(['d.status', 'n.type', 'n.title', 'n.body', 'n.data', 'u.email', 'u.first_name']));

        if ($row === null || $row->status === DeliveryStatus::Sent->value) {
            return;
        }

        if (! $guard->allows($row->email)) {
            $this->record($db, DeliveryStatus::Failed, 'suppressed: '.$guard->reason($row->email));

            return;
        }

        $data = json_decode((string) $row->data, true) ?: [];
        Mail::to($row->email)->send(new NotificationMail($row->title, $row->body, $data['url'] ?? null, $row->first_name));
        $this->record($db, DeliveryStatus::Sent);
    }

    public function failed(?Throwable $e): void
    {
        $this->record(app(DatabaseContext::class), DeliveryStatus::Failed, $e?->getMessage());
    }

    private function record(DatabaseContext $db, DeliveryStatus $status, ?string $error = null): void
    {
        $db->system('notify.delivered', fn () => DB::table('notification_deliveries')->where('id', $this->deliveryId)->update([
            'status' => $status->value,
            'attempt_count' => DB::raw('attempt_count + 1'),
            'last_error' => $error !== null ? mb_substr($error, 0, 500) : null,
            'sent_at' => $status === DeliveryStatus::Sent ? now() : null,
        ]));
    }
}
