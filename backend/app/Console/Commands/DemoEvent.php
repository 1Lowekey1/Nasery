<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\User;
use App\Services\Events\EventManager;
use App\Services\Ticketing\TierManager;
use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The always-ready event for live demos (advisor / panel): a public event by
 * Davao Dream Events whose check-in door is open whenever you need it, so
 * "buy a ticket → get the QR → scan it at the door" works on the spot.
 *
 *   php artisan nasery:demo:event           create it, or keep it ready (the scheduler runs this hourly)
 *   php artisan nasery:demo:event --reset   start over: retire the current one (and its tickets), make a fresh one
 *
 * Its one session always starts within the next ~2 hours and lasts 6, so the
 * door (open from `nasery.checkin.opens_before_minutes` before the start) is
 * open and tickets are on sale at any hour. Needs the demo dataset
 * (nasery:demo:seed) for the organizer and its staff.
 */
#[Signature('nasery:demo:event {--reset : Retire the current demo event and its tickets, then create a fresh one}')]
#[Description('Create or keep ready the always-open demo event (ticket purchase → check-in)')]
class DemoEvent extends Command
{
    public const NAME = 'NaSeRy Live Demo Night';

    public function handle(DatabaseContext $ctx): int
    {
        $owner = User::query()->where('email', 'maria@'.config('nasery.demo.domain'))->first();
        $org = $owner ? Organization::query()->where('slug', 'davao-dream-events')->whereNull('deleted_at')->first() : null;
        if ($owner === null || $org === null) {
            if ($this->option('reset')) {
                $this->error('Build the demo dataset first: php artisan nasery:demo:seed');

                return self::FAILURE;
            }

            return self::SUCCESS; // scheduled run on a site without the demo dataset: nothing to do
        }
        $member = OrganizationMember::query()->where('organization_id', $org->id)->where('user_id', $owner->id)->firstOrFail();

        $event = Event::query()->where('organization_id', $org->id)->where('name', self::NAME)->whereNull('deleted_at')
            ->whereNotIn('status', ['cancelled', 'completed'])->latest('created_at')->first();

        if ($event && $this->option('reset')) {
            $this->retire($event);
            $event = null;
        }

        if ($event === null) {
            $event = $ctx->asUser($owner->id, fn () => $this->create($org, $member));
            $this->info('Demo event created.');
        } else {
            $this->keepOpen($event);
        }

        $session = DB::table('event_sessions')->where('event_id', $event->id)->whereNull('deleted_at')->first();
        $tz = $event->timezone ?: 'Asia/Manila';
        $this->line('  '.self::NAME.': '.rtrim((string) config('nasery.auth.frontend_url'), '/').'/events/'.$event->id);
        $this->line('  Starts '.CarbonImmutable::parse($session->starts_at)->setTimezone($tz)->format('D g:i A').', check-in open until '
            .CarbonImmutable::parse($session->ends_at)->setTimezone($tz)->format('D g:i A').'.');
        $this->line('  Tickets sold: '.DB::table('tickets')->where('event_id', $event->id)->whereIn('status', ['valid', 'used'])->count()
            .', checked in: '.DB::table('attendance_records')->where('event_id', $event->id)->count().'.');

        return self::SUCCESS;
    }

    private function create(Organization $org, OrganizationMember $member): Event
    {
        [$start, $end] = $this->window();
        $event = app(EventManager::class)->create($org, $member, [
            'name' => self::NAME,
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Concert')->value('id'),
            'description' => 'A short live program by Davao Dream Events. Get a ticket (free or paid), show the QR code from your e-mail '
                .'or My Tickets at the door, and you are in. This event is always open for NaSeRy demonstrations.',
            'visibility' => 'public', 'venue_name' => 'NaSeRy Demo Hall, Davao City', 'overall_capacity' => 1000,
            'sessions' => [['name' => 'Main program', 'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $end->format('Y-m-d\TH:i')]],
        ]);
        foreach ([
            ['Free Pass', 0, 400, 'Quick way to try the flow: no payment needed.'],
            ['Regular', 150, 400, 'Paid through PayMongo test mode: use a PayMongo test card, no real money.'],
            ['VIP', 500, 200, 'Front seats. Also PayMongo test mode.'],
        ] as $i => [$name, $price, $capacity, $about]) {
            app(TierManager::class)->create($event, ['name' => $name, 'price' => $price, 'capacity' => $capacity, 'status' => 'on_sale', 'description' => $about, 'sort_order' => $i]);
        }

        return app(EventManager::class)->transition($event->refresh(), 'publish');
    }

    /** Seed shortcut: move the session so it starts within ~2 hours (the door opens 3 hours before). */
    private function keepOpen(Event $event): void
    {
        $session = DB::table('event_sessions')->where('event_id', $event->id)->whereNull('deleted_at')->first();
        if ($session && CarbonImmutable::parse($session->ends_at)->gt(now()->addHours(4))) {
            return; // still comfortably open
        }
        [$start, $end] = $this->window();
        DB::table('event_sessions')->where('event_id', $event->id)->whereNull('deleted_at')
            ->update(['starts_at' => $start->utc(), 'ends_at' => $end->utc(), 'updated_at' => now()]);
        $this->line('Moved the demo session to keep check-in open.');
    }

    /** Retire without refunds (demo money only): the event and its tickets stop working and disappear from lists. */
    private function retire(Event $event): void
    {
        DB::transaction(function () use ($event) {
            DB::table('qr_tickets')->whereIn('ticket_id', DB::table('tickets')->where('event_id', $event->id)->select('id'))
                ->where('status', '!=', 'invalidated')->update(['status' => 'invalidated', 'invalidated_at' => now(), 'invalidation_reason' => 'Demo event reset']);
            DB::table('tickets')->where('event_id', $event->id)->whereIn('status', ['pending', 'valid'])->update(['status' => 'cancelled']);
            DB::table('events')->where('id', $event->id)->update(['status' => 'cancelled', 'cancelled_reason' => 'Demo event reset',
                'cancelled_at' => now(), 'deleted_at' => now(), 'updated_at' => now()]);
        });
        $this->line('Retired the previous demo event and its tickets.');
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(): array
    {
        $start = CarbonImmutable::now('Asia/Manila')->addHours(2)->startOfHour();

        return [$start, $start->addHours(6)];
    }
}
