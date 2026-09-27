<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Jobs\DeliverNotificationEmail;
use App\Support\Database\DatabaseContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * B13 user notifications: an in-app row for every recipient, plus an
 * e-mail when the user's preference allows it (critical types always).
 * E-mails are queued after commit and logged in notification_deliveries.
 * Runs in the system context: the app role may only touch its own rows.
 *
 * $dedupe makes a notification one-off per recipient (e.g. "event full"
 * once per event), matched on type + data.dedupe.
 */
class Notifier
{
    public function __construct(private readonly DatabaseContext $db) {}

    /**
     * @param  string|list<string>|null  $userIds  null = nobody (e.g. a guest without an account)
     * @param  array<string, mixed>  $data
     * @return int notifications created
     */
    public function send(string|array|null $userIds, string $type, string $title, ?string $body = null, array $data = [], ?string $url = null, ?string $dedupe = null): int
    {
        if (! NotificationTypes::exists($type)) {
            throw new InvalidArgumentException("Unknown notification type {$type}.");
        }

        $userIds = array_values(array_unique(array_filter((array) $userIds)));

        if ($userIds === []) {
            return 0;
        }

        $critical = NotificationTypes::critical($type);
        $data = array_filter([...$data, 'url' => $url, 'dedupe' => $dedupe], fn ($v) => $v !== null);

        $emails = $this->db->system('notify.send', function () use ($userIds, $type, $title, $body, $data, $critical, $dedupe) {
            if ($dedupe !== null) {
                $already = DB::table('notifications')->whereIn('recipient_user_id', $userIds)->where('type', $type)
                    ->where('data->dedupe', $dedupe)->pluck('recipient_user_id')->all();
                $userIds = array_values(array_diff($userIds, $already));
            }

            $emailChoice = $critical ? [] : DB::table('notification_preferences')->whereIn('user_id', $userIds)
                ->where('notification_type', $type)->where('channel', NotificationChannel::Email->value)
                ->pluck('is_enabled', 'user_id')->all();

            $queued = [];
            foreach ($userIds as $userId) {
                $id = (string) Str::uuid7();
                DB::table('notifications')->insert([
                    'id' => $id, 'recipient_user_id' => $userId, 'type' => $type, 'title' => $title, 'body' => $body,
                    'data' => json_encode($data), 'is_critical' => $critical, 'created_at' => now(),
                ]);

                $wantsEmail = config('nasery.mail.enabled', true) !== false
                    && ($critical || (bool) ($emailChoice[$userId] ?? NotificationTypes::emailByDefault($type)));
                if ($wantsEmail) {
                    $delivery = (string) Str::uuid7();
                    DB::table('notification_deliveries')->insert([
                        'id' => $delivery, 'notification_id' => $id, 'channel' => NotificationChannel::Email->value,
                        'status' => DeliveryStatus::Queued->value, 'created_at' => now(),
                    ]);
                    $queued[] = $delivery;
                }
            }

            return ['created' => count($userIds), 'queued' => $queued];
        });

        foreach ($emails['queued'] as $deliveryId) {
            DeliverNotificationEmail::dispatch($deliveryId)->afterCommit();
        }

        return $emails['created'];
    }
}
