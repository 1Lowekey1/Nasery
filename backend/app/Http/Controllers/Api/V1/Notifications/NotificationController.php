<?php

namespace App\Http\Controllers\Api\V1\Notifications;

use App\Enums\AccountType;
use App\Enums\NotificationChannel;
use App\Http\Controllers\Controller;
use App\Services\Notifications\NotificationTypes;
use App\Support\Http\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * B13 in-app notifications and e-mail preferences for the signed-in user.
 * Everything here runs under RLS (0016: self only), so a user can only
 * ever read or change their own rows.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['unread' => ['sometimes', 'boolean'], 'page' => ['sometimes', 'integer', 'min:1']]);

        $page = DB::table('notifications')->where('recipient_user_id', $request->user()->id)->whereNull('deleted_at')
            ->when($request->boolean('unread'), fn ($q) => $q->whereNull('read_at'))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(perPage: 20, page: (int) ($data['page'] ?? 1), columns: ['id', 'type', 'title', 'body', 'data', 'is_critical', 'read_at', 'created_at'])
            ->through(fn ($n) => $this->present($n));

        return ApiResponse::success($page, meta: ['unread_count' => $this->unread($request)]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['count' => $this->unread($request)]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        DB::table('notifications')->where('id', $notification)->where('recipient_user_id', $request->user()->id)
            ->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(['count' => $this->unread($request)]);
    }

    public function readAll(Request $request): JsonResponse
    {
        DB::table('notifications')->where('recipient_user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return ApiResponse::success(['count' => 0]);
    }

    public function preferences(Request $request): JsonResponse
    {
        $chosen = DB::table('notification_preferences')->where('user_id', $request->user()->id)
            ->where('channel', NotificationChannel::Email->value)->pluck('is_enabled', 'notification_type');

        return ApiResponse::success(collect($this->typesFor($request))->map(fn ($meta, $type) => [
            'type' => $type,
            'label' => $meta['label'],
            'group' => $meta['group'],
            'critical' => $meta['critical'],
            'email' => $meta['critical'] || (bool) ($chosen[$type] ?? $meta['email']),
        ])->values());
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $types = array_keys($this->typesFor($request));
        $data = $request->validate([
            'preferences' => ['required', 'array', 'max:50'],
            'preferences.*.type' => ['required', 'string', Rule::in($types)],
            'preferences.*.email' => ['required', 'boolean'],
        ]);

        foreach ($data['preferences'] as $pref) {
            if (NotificationTypes::critical($pref['type'])) {
                continue; // critical notifications ignore preferences (0016)
            }

            DB::table('notification_preferences')->upsert([[
                'id' => (string) Str::uuid7(), 'user_id' => $request->user()->id, 'notification_type' => $pref['type'],
                'channel' => NotificationChannel::Email->value, 'is_enabled' => (bool) $pref['email'], 'created_at' => now(),
            ]], ['user_id', 'notification_type', 'channel'], ['is_enabled']);
        }

        return $this->preferences($request);
    }

    /** @return array<string, array{label: string, group: string, critical: bool, email: bool}> */
    private function typesFor(Request $request): array
    {
        $groups = match ($request->user()->account_type) {
            AccountType::Business => ['organizer', 'tickets', 'account'],
            AccountType::Client => ['client', 'tickets', 'account'],
            AccountType::Admin => ['admin', 'account'],
        };

        return array_filter(NotificationTypes::ALL, fn ($meta) => in_array($meta['group'], $groups, true));
    }

    private function unread(Request $request): int
    {
        return DB::table('notifications')->where('recipient_user_id', $request->user()->id)->whereNull('deleted_at')->whereNull('read_at')->count();
    }

    /** @return array<string, mixed> */
    private function present(object $n): array
    {
        $data = json_decode((string) $n->data, true) ?: [];

        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'url' => $data['url'] ?? null,
            'critical' => (bool) $n->is_critical,
            'read' => $n->read_at !== null,
            'created_at' => CarbonImmutable::parse($n->created_at)->toIso8601String(),
        ];
    }
}
