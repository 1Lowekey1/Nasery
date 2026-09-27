<?php

namespace App\Services\CheckIn;

use App\Models\Event;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * When the door is open (B11, user): a session accepts guests from
 * `nasery.checkin.opens_before_minutes` before it starts until it ends.
 * Shared by check-in and the walk-in counter (B12).
 */
class SessionWindow
{
    /** @return Collection<int, object> id, name, starts_at, ends_at */
    public function sessions(Event $event): Collection
    {
        return DB::table('event_sessions')->where('event_id', $event->id)->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')->orderBy('starts_at')->get(['id', 'name', 'starts_at', 'ends_at']);
    }

    public function isOpen(object $session): bool
    {
        $now = now();

        return $now->gte(CarbonImmutable::parse($session->starts_at)->subMinutes($this->opensBefore()))
            && $now->lt(CarbonImmutable::parse($session->ends_at));
    }

    /**
     * The session to admit into: the one asked for (if open), otherwise the
     * open session starting closest to now.
     *
     * @return array{open: bool, row?: object, message?: string}
     */
    public function pick(Event $event, ?string $sessionId): array
    {
        $sessions = $this->sessions($event);
        $open = $sessions->filter(fn ($s) => $this->isOpen($s));

        if ($sessionId !== null) {
            $chosen = $sessions->firstWhere('id', $sessionId);

            if ($chosen !== null && $this->isOpen($chosen)) {
                return ['open' => true, 'row' => $chosen];
            }
        } elseif ($open->isNotEmpty()) {
            return ['open' => true, 'row' => $open->sortBy(fn ($s) => abs(CarbonImmutable::parse($s->starts_at)->diffInSeconds(now())))->first()];
        }

        $next = $sessions->first(fn ($s) => CarbonImmutable::parse($s->starts_at)->subMinutes($this->opensBefore())->isFuture());

        return ['open' => false, 'message' => $next !== null
            ? 'Check-in opens '.$this->local($next->starts_at, $event)->subMinutes($this->opensBefore())->format('D, M j \a\t g:i A').'.'
            : 'Check-in has closed: every session of this event has ended.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(object $session, Event $event): array
    {
        $start = $this->local($session->starts_at, $event);

        return [
            'id' => $session->id,
            'label' => $session->name ?: $start->format('D, M j · g:i A'),
            'starts_at' => $start->toIso8601String(),
            'ends_at' => $this->local($session->ends_at, $event)->toIso8601String(),
            'opens_at' => $start->subMinutes($this->opensBefore())->toIso8601String(),
            'is_open' => $this->isOpen($session),
        ];
    }

    public function opensBefore(): int
    {
        return (int) config('nasery.checkin.opens_before_minutes', 180);
    }

    private function local(string $at, Event $event): CarbonImmutable
    {
        return CarbonImmutable::parse($at)->setTimezone($event->timezone ?: 'Asia/Manila');
    }
}
