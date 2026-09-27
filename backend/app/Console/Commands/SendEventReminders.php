<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Services\Notifications\Notifier;
use App\Services\Notifications\Recipients;
use App\Services\Tickets\TicketMailer;
use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Day-before reminders (B13, user). For sessions starting within
 * `nasery.mail.reminder_hours` (default 24, at least an hour away): ticket
 * holders get their QR again (once per ticket per session, logged in
 * laravel.mail_deliveries), and the event team gets a "tomorrow" heads-up
 * (once per session). Scheduled hourly; safe to run any time.
 */
#[Signature('nasery:reminders:send')]
#[Description('E-mail QR reminders for sessions starting within the next day')]
class SendEventReminders extends Command
{
    public function handle(DatabaseContext $db, TicketMailer $mailer, Notifier $notifier, Recipients $recipients): int
    {
        $sessions = $db->system('reminders.sessions', fn () => DB::table('event_sessions as s')->join('events as e', 'e.id', '=', 's.event_id')
            ->whereIn('e.status', ['published', 'ongoing'])->whereNull('s.deleted_at')->where('s.status', '!=', 'cancelled')
            ->whereBetween('s.starts_at', [now()->addHour(), now()->addHours((int) config('nasery.mail.reminder_hours', 24))])
            ->get(['s.id', 's.name', 's.starts_at', 's.event_id', 'e.timezone']));

        $emails = 0;
        foreach ($sessions as $session) {
            $pending = $db->system('reminders.tickets', function () use ($session) {
                $reminded = DB::connection('system')->table('mail_deliveries')->where('kind', 'ticket_reminder')->where('session_id', $session->id)
                    ->pluck('ticket_ids')->flatMap(fn ($ids) => json_decode($ids, true))->all();

                return DB::table('tickets')->where('event_id', $session->event_id)->where('status', 'valid')->whereNull('deleted_at')
                    ->whereNotIn('id', $reminded)->pluck('id')->all();
            });

            if ($pending !== []) {
                $emails += $mailer->send($pending, 'ticket_reminder', $session->id)['queued'];
            }

            $event = Event::query()->find($session->event_id);
            $when = CarbonImmutable::parse($session->starts_at)->setTimezone($session->timezone ?: 'Asia/Manila')->format('D, M j \a\t g:i A');
            $notifier->send($recipients->forEvent($event, ['event.update', 'checkin.perform', 'task.manage']), 'team.event_tomorrow',
                "Tomorrow: {$event->name}", ($session->name ? "{$session->name} starts " : 'It starts ').$when.'. Check the preparation tasks and the door team.',
                ['event_id' => $event->id, 'session_id' => $session->id], "/organizer/eventmanagement/event-details?event={$event->id}", "tomorrow:{$session->id}");
        }

        $this->info("Reminders: {$sessions->count()} session(s), {$emails} e-mail(s) queued.");

        return self::SUCCESS;
    }
}
