<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

// Expired idempotency keys are no longer replayable; drop them daily.
Schedule::call(fn () => DB::connection('system')->table('idempotency_keys')->where('expires_at', '<', now())->delete())
    ->daily()
    ->name('idempotency-keys:prune')
    ->onOneServer();

// B9: release checkout holds whose 15 minutes ran out (capacity reads already ignore them).
Schedule::call(fn () => DB::scalar('select expire_stale_holds()'))
    ->everyMinute()
    ->name('ticket-holds:expire')
    ->onOneServer()
    ->withoutOverlapping();

// B4: cancel open inquiries past the auto-close window (platform setting).
Schedule::command('nasery:inquiries:close-stale')->dailyAt('01:00')->onOneServer();

// B13: day-before QR reminders to ticket holders and a heads-up to the event team.
Schedule::command('nasery:reminders:send')->hourly()->onOneServer()->withoutOverlapping();

// B18: subscription renewals, grace periods and cancellations at period end.
Schedule::command('nasery:billing:cycle')->hourly()->onOneServer()->withoutOverlapping();

// Demo: keep the always-open demo event's check-in door open (does nothing without the demo dataset).
Schedule::command('nasery:demo:event')->hourly()->onOneServer()->withoutOverlapping();

// 0060: cancellation requests nobody answered in time.
Schedule::command('nasery:bookings:expire')->hourly()->onOneServer()->withoutOverlapping();

// Uploaded pictures no account, organizer or event uses anymore.
Schedule::command('nasery:media:prune')->dailyAt('03:30')->onOneServer();
