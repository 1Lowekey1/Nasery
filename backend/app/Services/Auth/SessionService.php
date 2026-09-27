<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The user's signed-in devices (laravel.sessions rows with their user_id).
 * Raw session ids are secrets, so clients only ever see sha256(id).
 */
class SessionService
{
    /**
     * @return list<array{id: string, ip_address: ?string, user_agent: ?string, last_active_at: string, current: bool}>
     */
    public function list(User $user, ?string $currentSessionId): array
    {
        return $this->query($user)
            ->orderByDesc('last_activity')
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn (object $row) => [
                'id' => self::publicId($row->id),
                'ip_address' => $row->ip_address,
                'user_agent' => $row->user_agent,
                'last_active_at' => Carbon::createFromTimestamp($row->last_activity)->toIso8601String(),
                'current' => $row->id === $currentSessionId,
            ])
            ->values()
            ->all();
    }

    /**
     * @return bool false when no such session belongs to the user
     */
    public function revoke(User $user, string $publicId): bool
    {
        $match = $this->query($user)->pluck('id')->first(fn (string $id) => hash_equals(self::publicId($id), $publicId));

        return $match !== null && $this->query($user)->where('id', $match)->delete() === 1;
    }

    public function revokeOthers(User $user, string $currentSessionId): int
    {
        return $this->query($user)->where('id', '!=', $currentSessionId)->delete();
    }

    public function revokeAll(User $user): int
    {
        return $this->query($user)->delete();
    }

    public static function publicId(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    private function query(User $user): Builder
    {
        return DB::connection('system')->table('sessions')->where('user_id', $user->id);
    }
}
