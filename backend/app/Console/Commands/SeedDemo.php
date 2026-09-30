<?php

namespace App\Console\Commands;

use App\Enums\AccountType;
use App\Enums\AdmissionType;
use App\Enums\ApprovalStatus;
use App\Models\Event;
use App\Models\Inquiry;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\Task;
use App\Models\Ticket;
use App\Models\TicketType;
use App\Models\User;
use App\Services\Admin\AccessRequests;
use App\Services\Admin\PlatformDirectory;
use App\Services\CheckIn\CheckInDesk;
use App\Services\CheckIn\WalkInDesk;
use App\Services\Events\EventManager;
use App\Services\Inquiries\InquiryManager;
use App\Services\Inquiries\InquiryThreads;
use App\Services\Inquiries\OrganizerInquiries;
use App\Services\Organizations\ApprovalWorkflow;
use App\Services\Organizations\OfferedServices;
use App\Services\Organizations\OrganizerOnboarding;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Payments;
use App\Services\Payments\RefundReason;
use App\Services\Preparation\PreparationManager;
use App\Services\Quotations\ClientQuotations;
use App\Services\Quotations\OrganizerQuotations;
use App\Services\Reports\ReportSharing;
use App\Services\Reviews\ReviewManager;
use App\Services\Staff\StaffManager;
use App\Services\Ticketing\GuestList;
use App\Services\Ticketing\SelfRegistration;
use App\Services\Ticketing\TierManager;
use App\Support\Database\DatabaseContext;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Builds the demo / presentation dataset: a small, realistic Davao
 * marketplace covering every role and stage, created through the real
 * services (same rules as the app) with a few seed-only shortcuts to put
 * things in the past (session times, created_at).
 *
 *   php artisan nasery:demo:seed           first time
 *   php artisan nasery:demo:seed --fresh   wipe the demo accounts and rebuild
 *
 * Accounts use @demo.nasery.test (never e-mailed; the test purge leaves
 * them alone) and share DEMO_PASSWORD from .env, which the team sets. E-mail
 * is switched off for the whole run, so re-seeding never mails real admins.
 */
#[Signature('nasery:demo:seed {--fresh : Delete the existing demo accounts first, then rebuild}')]
#[Description('Create (or rebuild) the demo dataset for presentations')]
class SeedDemo extends Command
{
    private DatabaseContext $ctx;

    private string $domain;

    /** @var array<string, User> */
    private array $users = [];

    /** @var array<string, Organization> */
    private array $orgs = [];

    public function handle(DatabaseContext $ctx): int
    {
        $this->ctx = $ctx;
        $this->domain = (string) config('nasery.demo.domain');
        // Demo sales never touch PayMongo, even when test keys are set.
        app()->instance(PaymentGateway::class, new FakeGateway);
        $password = (string) config('nasery.demo.password');

        if (strlen($password) < 8) {
            $this->error('Set DEMO_PASSWORD in backend/.env first (at least 8 characters, letters and numbers). It is the password of every demo account.');

            return self::FAILURE;
        }

        $existing = DB::table('users')->where('email', 'like', '%@'.$this->domain)->count();
        if ($existing > 0 && ! $this->option('fresh')) {
            $this->error("The demo dataset already exists ({$existing} accounts). Use --fresh to rebuild it.");

            return self::FAILURE;
        }
        if ($existing > 0 && $this->call('nasery:test-data:purge', ['--domain' => $this->domain, '--force' => true]) !== self::SUCCESS) {
            return self::FAILURE;
        }

        // No e-mail while seeding (in-app notifications are still created).
        config(['nasery.mail.enabled' => false]);

        $this->line('Building the demo dataset…');
        $this->catalog();
        $this->people($password);
        $this->organizers();
        $this->team();
        $this->bookings();
        $this->publicEvents();
        $this->platform();

        $this->newLine();
        $this->info('Demo ready. Every account below uses DEMO_PASSWORD.');
        $this->table(['Role', 'E-mail', 'Name'], collect($this->users)->map(fn (User $u, $key) => [
            str($key)->before(':')->toString(), $u->email, trim("{$u->first_name} {$u->last_name}"),
        ])->values()->all());

        return self::SUCCESS;
    }

    // ---- Accounts ------------------------------------------------------------

    private function people(string $password): void
    {
        $this->users['admin:admin'] = $this->user('admin', 'Dana', 'Admin', AccountType::Admin, $password);

        foreach ([
            'maria' => ['Maria', 'Santos'], 'carlo' => ['Carlo', 'Reyes'], 'liza' => ['Liza', 'Tan'],
            'jomar' => ['Jomar', 'Uy'], 'rhea' => ['Rhea', 'Lim'],
            'ken' => ['Ken', 'Aquino'], 'aira' => ['Aira', 'Mendoza'], 'paolo' => ['Paolo', 'Rivera'],
        ] as $key => [$first, $last]) {
            $this->users["organizer:{$key}"] = $this->user($key, $first, $last, AccountType::Business, $password);
        }

        foreach ([
            'ella' => ['Ella', 'Reyes'], 'miguel' => ['Miguel', 'Bautista'], 'sofia' => ['Sofia', 'Garcia'],
            'joshua' => ['Joshua', 'Dela Cruz'], 'andrea' => ['Andrea', 'Villanueva'], 'rafael' => ['Rafael', 'Gomez'],
            'bea' => ['Bea', 'Santos'], 'carl' => ['Carl', 'Ramos'], 'dana' => ['Dana', 'Flores'],
            'enzo' => ['Enzo', 'Navarro'], 'faith' => ['Faith', 'Castillo'], 'gino' => ['Gino', 'Morales'],
        ] as $key => [$first, $last]) {
            $this->users["client:{$key}"] = $this->user($key, $first, $last, AccountType::Client, $password);
        }
    }

    private function user(string $local, string $first, string $last, AccountType $type, string $password): User
    {
        $user = new User;
        $user->forceFill([
            'email' => "{$local}@{$this->domain}", 'first_name' => $first, 'last_name' => $last, 'account_type' => $type,
            'password_hash' => $password, 'phone' => '0917 '.random_int(100, 999).' '.random_int(1000, 9999),
        ])->save();
        $user->forceFill(['email_verified_at' => now()])->save();

        return $user->refresh();
    }

    private function u(string $key): User
    {
        return collect($this->users)->first(fn ($u, $k) => str_ends_with($k, ":{$key}"));
    }

    // ---- Reference data ----------------------------------------------------------

    /** The platform service catalog (admin-curated in B17); added once, kept by purges. */
    private function catalog(): void
    {
        foreach ([
            ['Event Coordination', 'Planning'], ['Catering', 'Food & Drinks'], ['Lights & Sound', 'Production'],
            ['Photo & Video', 'Media'], ['Venue Styling', 'Design'], ['Hosting / Emcee', 'Program'],
            ['Tents, Tables & Chairs', 'Rentals'], ['Cakes & Desserts', 'Food & Drinks'], ['Invitations & Printing', 'Design'],
            ['Security & Ushers', 'Staffing'],
        ] as [$name, $category]) {
            if (! DB::table('services')->whereNull('deleted_at')->whereRaw('lower(name) = lower(?)', [$name])->exists()) {
                DB::table('services')->insert(['id' => (string) Str::uuid7(), 'name' => $name, 'category' => $category, 'is_system_default' => true, 'created_at' => now()]);
            }
        }
    }

    // ---- Organizers ------------------------------------------------------------

    private function organizers(): void
    {
        $specs = [
            'dream' => ['maria', 'Davao Dream Events', 'Davao Dream Events Co.', 'Poblacion, Davao City', 7.0731, 125.6128,
                'Full-service weddings, debuts and corporate events across Davao since 2015. One coordinator from first meeting to last dance.',
                ['Event Coordination' => 45000, 'Venue Styling' => 30000, 'Hosting / Emcee' => 8000, 'Lights & Sound' => 18000], ApprovalStatus::Approved, 'pro'],
            'grand' => ['carlo', 'Mindanao Grand Occasions', 'Mindanao Grand Occasions Inc.', 'Lanang, Davao City', 7.1007, 125.6480,
                'Corporate launches, conferences and family celebrations with in-house catering.',
                ['Catering' => 650, 'Event Coordination' => 38000, 'Tents, Tables & Chairs' => 12000], ApprovalStatus::Approved, 'starter'],
            'samal' => ['liza', 'Samal Island Weddings', 'Samal Island Weddings', 'Island Garden City of Samal', 7.0732, 125.7094,
                'Beach weddings and sunset celebrations on Samal Island.',
                ['Event Coordination' => 55000, 'Photo & Video' => 25000], ApprovalStatus::Approved, 'free'],
            'toril' => ['jomar', 'Toril Party Planners', 'Toril Party Planners', 'Toril, Davao City', 7.0190, 125.4978,
                'Kiddie parties and debuts in the south of Davao.', ['Event Coordination' => 20000], ApprovalStatus::Pending, 'free'],
            'buhangin' => ['rhea', 'Buhangin Balloons & Co.', 'Buhangin Balloons & Co.', 'Buhangin, Davao City', 7.1135, 125.6147,
                'Balloon styling and party backdrops.', ['Venue Styling' => 9000], ApprovalStatus::UnderReview, 'free'],
        ];

        // years, service radius (km), event tags, specialties, banner color, website (example.com: reserved, goes nowhere)
        $details = [
            'dream' => ['5–10 years', 40, ['Wedding', 'Debut', 'Corporate Event'], ['Full Event Coordination', 'Decor & Styling'], '#be123c', 'https://davaodreamevents.example.com'],
            'grand' => ['10+ years', 60, ['Corporate Event', 'Conference', 'Birthday Party', 'Christening'], ['Catering Services', 'Full Event Coordination', 'Venue Sourcing'], '#1d4ed8', null],
            'samal' => ['3–5 years', 30, ['Wedding'], ['Decor & Styling', 'Photography & Videography'], '#15803d', null],
        ];

        foreach ($specs as $key => [$ownerKey, $name, $legal, $location, $lat, $lng, $about, $services, $status, $plan]) {
            $owner = $this->u($ownerKey);
            $org = $this->as($owner, fn () => app(OrganizerOnboarding::class)->create($owner, [
                'legal_name' => $legal, 'display_name' => $name,
                'profile' => ['business_name' => $name, 'description' => $about, 'location_text' => $location, 'latitude' => $lat, 'longitude' => $lng,
                    'contact_email' => "hello@{$key}.{$this->domain}", 'contact_phone' => '082 '.random_int(200, 299).' '.random_int(1000, 9999)],
            ]));

            if ($status !== ApprovalStatus::Pending) {
                $this->as($this->u('admin'), fn () => app(ApprovalWorkflow::class)->decide($org, $status === ApprovalStatus::Approved ? ApprovalStatus::Approved : ApprovalStatus::UnderReview, null));
            }
            if ($status === ApprovalStatus::Approved) {
                DB::table('organization_profiles')->where('organization_id', $org->id)->update(['is_published' => true]); // seed shortcut: profile published
            }
            // 0046: the business profile panel's details (shown on the public organizer page).
            if (isset($details[$key])) {
                [$years, $radius, $tags, $specialties, $color, $website] = $details[$key];
                DB::table('organization_profiles')->where('organization_id', $org->id)->update([
                    'years_experience' => $years, 'service_radius_km' => $radius,
                    'event_tags' => json_encode($tags), 'specialties' => json_encode($specialties),
                    'banner_color' => $color, 'website' => $website,
                ]);
            }

            foreach ($services as $service => $price) {
                $serviceId = DB::table('services')->whereNull('deleted_at')->where('name', $service)->value('id');
                $this->as($owner, fn () => app(OfferedServices::class)->create($org, ['service_id' => $serviceId, 'base_price' => $price, 'is_active' => true]));
            }
            if ($key === 'dream' || $key === 'grand') {
                $this->as($owner, function () use ($org, $key) {
                    $options = $key === 'dream'
                        ? [['Wedding coordination package', 'flat', 45000], ['Styling per guest', 'per_guest', 350], ['Emcee (4 hours)', 'flat', 8000]]
                        : [['Buffet catering per guest', 'per_guest', 650], ['Conference setup', 'flat', 25000]];
                    foreach ($options as $i => [$label, $unit, $price]) {
                        $org->priceOptions()->create(['name' => $label, 'pricing_unit' => $unit, 'price' => $price, 'is_active' => true, 'sort_order' => $i]);
                    }
                });
            }

            $this->plan($org, $plan);
            $this->orgs[$key] = $org->refresh();
        }
    }

    /** Seed shortcut: an active paid plan with its paid invoice (as if bought last month). */
    private function plan(Organization $org, string $slug): void
    {
        if ($slug === 'free') {
            return;
        }
        $plan = DB::table('plans')->where('slug', $slug)->first();
        $cycle = $slug === 'pro' ? 'yearly' : 'monthly';
        $start = now()->subDays(20);
        $end = $cycle === 'yearly' ? $start->copy()->addYear() : $start->copy()->addMonth();
        $amount = $cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        $subId = (string) Str::uuid7();
        $invoiceId = (string) Str::uuid7();
        DB::table('subscriptions')->insert(['id' => $subId, 'organization_id' => $org->id, 'plan_id' => $plan->id, 'billing_cycle' => $cycle, 'status' => 'active',
            'current_period_start' => $start, 'current_period_end' => $end, 'created_at' => $start, 'updated_at' => $start]);
        DB::table('subscription_invoices')->insert(['id' => $invoiceId, 'subscription_id' => $subId, 'amount' => $amount, 'billing_period_start' => $start,
            'billing_period_end' => $end, 'status' => 'paid', 'issued_at' => $start, 'created_at' => $start]);
        DB::table('payments')->insert(['id' => (string) Str::uuid7(), 'subscription_invoice_id' => $invoiceId, 'paymongo_payment_intent_id' => 'demo_'.Str::lower(Str::random(20)),
            'amount' => $amount, 'currency' => 'PHP', 'status' => 'paid', 'paid_at' => $start, 'created_at' => $start, 'updated_at' => $start]);
    }

    private function team(): void
    {
        $org = $this->orgs['dream'];
        $owner = $this->u('maria');
        foreach (['ken' => 'coordinator', 'aira' => 'checkin', 'paolo' => 'finance'] as $who => $preset) {
            $member = $this->as($owner, function () use ($org, $owner, $who, $preset) {
                $role = app(StaffManager::class)->presetRole($org, $owner, $preset);

                return app(StaffManager::class)->invite($org, $owner, $this->u($who)->email, $role);
            });
            $this->as($this->u($who), fn () => app(StaffManager::class)->respond($this->u($who), $member->id, true));
        }
    }

    // ---- Client bookings (inquiry → quotation → event) ------------------------

    private function bookings(): void
    {
        [$dream, $grand, $samal] = [$this->orgs['dream'], $this->orgs['grand'], $this->orgs['samal']];

        // 1. Ella's wedding with Davao Dream: completed, guests checked in, report shared, 5★ with a reply.
        $wedding = $this->won('ella', 'Wedding', 120, [$dream], $dream, 'maria', 'Garden Wedding Package', 185000, daysAgo: 75);
        $weddingEvent = $this->eventFrom($dream, 'maria', $wedding, 'Reyes–Santos Garden Wedding', 'Pearl Farm Pavilion, Davao City', 8);
        $guests = $this->guests($weddingEvent, 'maria', 'Guest', 0, ['Lara Reyes', 'Jun Santos', 'Mila Cruz', 'Ramon Reyes', 'Tess Lim', 'Oscar Tan', 'Nina Uy', 'Paul Go', 'Rina Sy', 'Leo Chua']);
        $this->happened($weddingEvent, 'maria', daysAgo: 21, hours: 6, scans: array_slice($guests, 0, 8), walkIns: ['Tita Nena Reyes'], headcount: 2);
        $this->as($this->u('maria'), fn () => app(ReportSharing::class)->share($weddingEvent->refresh(), $this->u('maria'), 'Thank you for celebrating with us!', false));
        $review = $this->as($this->u('ella'), fn () => app(ReviewManager::class)->create($this->u('ella'), $weddingEvent->refresh(), 5,
            'Maria and her team handled everything, from the rain plan to the last dance. Our guests are still talking about the styling.'));
        $this->as($this->u('maria'), fn () => app(ReviewManager::class)->reply($dream, $this->member($dream, 'maria'), $review['id'], 'Thank you, Ella! It was an honor to be part of your day.'));

        // 2. Andrea's christening with Mindanao Grand: completed, 3★ review that the organizer reported.
        $christening = $this->won('andrea', 'Christening', 60, [$grand], $grand, 'carlo', 'Christening Reception', 48000, daysAgo: 40);
        $christeningEvent = $this->eventFrom($grand, 'carlo', $christening, 'Villanueva Christening Reception', 'Grand Regal Hotel, Lanang', 6);
        $cGuests = $this->guests($christeningEvent, 'carlo', 'Guest', 0, ['Ana Villanueva', 'Ben Cruz', 'Cora Diaz', 'Dino Lopez', 'Edna Sy', 'Fely Uy']);
        $this->happened($christeningEvent, 'carlo', daysAgo: 9, hours: 4, scans: array_slice($cGuests, 0, 5), walkIns: [], headcount: 1);
        $cReview = $this->as($this->u('andrea'), fn () => app(ReviewManager::class)->create($this->u('andrea'), $christeningEvent->refresh(), 3,
            'Nice venue, but the food came out 40 minutes late and the host mispronounced our baby\'s name.'));
        $this->as($this->u('carlo'), fn () => app(ReviewManager::class)->report($this->u('carlo'), $cReview['id'], 'The delay was caused by the client extending the ceremony; we have the timeline.'));

        // 3. Joshua's 18th birthday with Mindanao Grand: upcoming, preparation under way.
        $birthday = $this->won('joshua', 'Birthday', 80, [$grand, $dream], $grand, 'carlo', 'Debut & Birthday Package', 62000, daysAgo: 12, loserOrg: $dream, loserOwner: 'maria', loserAmount: 70000);
        $birthdayEvent = $this->eventFrom($grand, 'carlo', $birthday, 'Joshua\'s 18th Birthday', 'Seda Abreeza, Davao City', 5, inDays: 25);
        $this->as($this->u('carlo'), function () use ($birthdayEvent) {
            app(EventManager::class)->transition($birthdayEvent->refresh(), 'publish');
            $checklist = app(PreparationManager::class)->createChecklist($birthdayEvent->refresh(), ['starter' => true], $this->u('carlo')->id);
            foreach (Task::query()->where('checklist_id', $checklist->id)->orderBy('created_at')->limit(5)->get() as $task) {
                app(PreparationManager::class)->updateTask($birthdayEvent->refresh(), $task, ['status' => 'done']);
            }
        });

        // 4. Miguel's corporate event: two bids in, still deciding, messages both ways.
        $corporate = $this->inquiry('miguel', 'Corporate Event', 200, [$dream, $grand], daysAgo: 4);
        $this->quote($dream, 'maria', $corporate, 'Corporate Anniversary Night', 240000);
        $this->quote($grand, 'carlo', $corporate, 'Conference + Dinner', 215000);
        $this->message($corporate, $dream, 'miguel', 'Hi! Can you handle a stage program with a live band for our anniversary?');
        $this->message($corporate, $dream, 'maria', 'Yes, we work with two bands in Davao. The package includes a 3-hour program with an emcee.');
        $this->message($corporate, $grand, 'miguel', 'Is the buffet halal-friendly? A few of our guests need that.');

        // 5. Sofia's debut: Samal quoted, Davao Dream declined (fully booked).
        $debut = $this->inquiry('sofia', 'Debut', 150, [$samal, $dream], daysAgo: 2);
        $this->quote($samal, 'liza', $debut, 'Sunset Debut on the Beach', 98000);
        $this->as($this->u('maria'), fn () => app(OrganizerInquiries::class)->decline($dream, Inquiry::query()->findOrFail($debut), 'We are fully booked on that date. Congratulations in advance!'));

        // 6. Rafael's anniversary (months ago): Davao Dream lost to Mindanao Grand (analytics history).
        $this->won('rafael', 'Anniversary', 50, [$dream, $grand], $grand, 'carlo', 'Anniversary Dinner', 42000, daysAgo: 100, loserOrg: $dream, loserOwner: 'maria', loserAmount: 55000);
    }

    // ---- Public ticketed events ------------------------------------------------

    private function publicEvents(): void
    {
        [$dream, $grand, $samal] = [$this->orgs['dream'], $this->orgs['grand'], $this->orgs['samal']];

        // Kadayawan Street Food Festival (Davao Dream): on sale, many buyers, one force-majeure refund (fee claim).
        [$fest, $tiers] = $this->publicEvent($dream, 'maria', 'Kadayawan Street Food Festival', 'Festival', 'Magsaysay Park, Davao City', 300, 6,
            [['General Admission', 250, 200], ['VIP Lounge', 800, 50], ['Kids (12 and under)', 0, 50]],
            'Davao\'s favorite food stalls, live music and a durian eating contest.');
        $paid = [];
        foreach (['bea' => [0, 3], 'carl' => [1, 2], 'dana' => [0, 2], 'enzo' => [0, 4], 'faith' => [1, 1], 'gino' => [2, 2], 'ella' => [0, 2], 'miguel' => [1, 2]] as $buyer => [$tier, $people]) {
            $paid[$buyer] = $this->buy($buyer, $fest, $tiers[$tier], $people);
        }
        if ($payment = $paid['carl']) {
            $this->as($this->u('maria'), fn () => app(Payments::class)->refund($payment->refresh(), 800, RefundReason::ForceMajeure,
                'Buyer\'s flight from Manila was cancelled by the typhoon', $this->u('maria'), false));
        }

        // Davao Tech Summit (Mindanao Grand): happening right now, doors open, people checking in.
        [$summit, $sTiers] = $this->publicEvent($grand, 'carlo', 'Davao Tech Summit 2026', 'Conference', 'SMX Convention Center, Lanang', 150, 1,
            [['Delegate', 0, 120], ['Workshop Pass', 1500, 30]], 'Talks and workshops on AI, fintech and startups in Mindanao.');
        foreach (['bea' => 0, 'carl' => 0, 'dana' => 1, 'enzo' => 0, 'faith' => 0, 'gino' => 1, 'joshua' => 0, 'sofia' => 0] as $buyer => $tier) {
            $this->buy($buyer, $summit, $sTiers[$tier], 1);
        }
        $this->liveNow($summit, 'carlo', scans: 5, headcount: 3);

        // Sunset Acoustic Night (Samal): on sale next weekend.
        [$acoustic, $aTiers] = $this->publicEvent($samal, 'liza', 'Sunset Acoustic Night', 'Concert', 'Kaputian Beach, Samal', 80, 12,
            [['Beachfront Seat', 500, 80]], 'Local acoustic acts as the sun sets over the Davao Gulf.');
        $this->buy('andrea', $acoustic, $aTiers[0], 2);
        $this->buy('rafael', $acoustic, $aTiers[0], 2);

        // Davao Food Expo (Davao Dream): last month, completed, ticket sales and attendance for analytics.
        [$expo, $eTiers] = $this->publicEvent($dream, 'maria', 'Davao Food Expo', 'Festival', 'SM Lanang Premier Event Center', 150, 3,
            [['Regular', 300, 150]], 'Local producers, cooking demos and tasting booths.');

        foreach (['bea' => 2, 'dana' => 3, 'enzo' => 2, 'faith' => 2, 'gino' => 1] as $buyer => $people) {
            $this->buy($buyer, $expo, $eTiers[0], $people);
        }
        $tokens = DB::table('tickets as t')->join('qr_tickets as q', 'q.ticket_id', '=', 't.id')->where('t.event_id', $expo->id)->where('t.status', 'valid')->pluck('t.id')->all();
        $scanTokens = collect($tokens)->take(8)->map(fn ($id) => app(GuestList::class)->qrToken(Ticket::query()->find($id)))->filter()->values()->all();
        $this->happened($expo, 'maria', daysAgo: 33, hours: 8, scans: $scanTokens, walkIns: ['Chef Boy Logro (guest)'], headcount: 6);
        $this->backdatePayments($expo->id, 36);
    }

    // ---- Platform ---------------------------------------------------------------

    private function platform(): void
    {
        $admin = $this->u('admin');
        app(PlatformDirectory::class)->saveAnnouncement(null, [
            'title' => 'Welcome to NaSeRy', 'audience' => 'all',
            'body' => 'Kadayawan season is here! Organizers: publish your events early. Clients: post your inquiry and compare quotations in one place.',
        ], $admin);
        // Mindanao Grand asked NaSeRy support to look at their workspace (waiting for an admin).
        $this->as($this->u('carlo'), fn () => app(AccessRequests::class)->invite($this->u('carlo'), $this->orgs['grand'],
            'Our Workshop Pass sales look lower than our bank deposits; please check the payment list.', 24));
    }

    // ---- Helpers ------------------------------------------------------------------

    private function as(User $user, Closure $fn): mixed
    {
        return $this->ctx->asUser($user->id, $fn);
    }

    private function member(Organization $org, string $ownerKey): OrganizationMember
    {
        return OrganizationMember::query()->where('organization_id', $org->id)->where('user_id', $this->u($ownerKey)->id)->firstOrFail();
    }

    private function typeId(string $name): ?string
    {
        return DB::table('event_types')->whereNull('organization_id')->where('name', $name)->value('id')
            ?? DB::table('event_types')->whereNull('organization_id')->where('name', 'Other')->value('id');
    }

    /** @param  list<Organization>  $orgs */
    private function inquiry(string $client, string $type, int $guests, array $orgs, int $daysAgo): string
    {
        $user = $this->u($client);
        $id = $this->as($user, function () use ($user, $type, $guests, $orgs) {
            $m = app(InquiryManager::class);
            $inquiry = $m->createDraft($user, [
                'event_type_id' => $this->typeId($type), 'requested_date' => now()->addMonths(3)->toDateString(), 'guest_count' => $guests,
                'location_text' => 'Davao City', 'services' => [['custom_service_name' => 'Event coordination']],
            ]);

            return $m->submit($inquiry, ['mode' => 'direct', 'organization_ids' => array_map(fn ($o) => $o->id, $orgs)])->id;
        });
        $at = now()->subDays($daysAgo);
        DB::table('inquiries')->where('id', $id)->update(['created_at' => $at, 'updated_at' => $at]);
        DB::table('inquiry_organizations')->where('inquiry_id', $id)->update(['created_at' => $at]);

        return $id;
    }

    private function quote(Organization $org, string $ownerKey, string $inquiryId, string $package, int $amount): string
    {
        return $this->as($this->u($ownerKey), fn () => app(OrganizerQuotations::class)->create($org, Inquiry::query()->findOrFail($inquiryId), [
            'package_name' => $package, 'proposal' => "Our {$package} covers planning, day-of coordination and a dedicated team lead.",
            'items' => [['description' => $package, 'quantity' => 1, 'unit_price' => $amount]],
        ], true)->id);
    }

    /** An inquiry the client awarded to $winner (optionally beating $loserOrg); returns the accepted quotation id. */
    private function won(string $client, string $type, int $guests, array $orgs, Organization $winner, string $winnerOwner, string $package, int $amount, int $daysAgo,
        ?Organization $loserOrg = null, ?string $loserOwner = null, ?int $loserAmount = null): string
    {
        $inquiry = $this->inquiry($client, $type, $guests, $orgs, $daysAgo);
        if ($loserOrg) {
            $this->quote($loserOrg, $loserOwner, $inquiry, "{$type} Package", $loserAmount);
        }
        $quotation = $this->quote($winner, $winnerOwner, $inquiry, $package, $amount);
        $this->as($this->u($client), fn () => app(ClientQuotations::class)->accept(Quotation::query()->findOrFail($quotation)));
        $at = now()->subDays(max(0, $daysAgo - 3));
        DB::table('quotations')->where('inquiry_id', $inquiry)->update(['created_at' => now()->subDays($daysAgo - 1), 'submitted_at' => now()->subDays($daysAgo - 1)]);
        DB::table('quotations')->where('id', $quotation)->update(['decided_at' => $at]);

        return $quotation;
    }

    private function eventFrom(Organization $org, string $ownerKey, string $quotationId, string $name, string $venue, int $capacityHint, int $inDays = 30): Event
    {
        $start = now('Asia/Manila')->addDays($inDays)->setTime(17, 0);

        return $this->as($this->u($ownerKey), fn () => app(EventManager::class)->convert($org, $this->member($org, $ownerKey), Quotation::query()->findOrFail($quotationId), [
            'name' => $name, 'venue_name' => $venue, 'visibility' => 'private', 'overall_capacity' => $capacityHint * 20,
            'sessions' => [['name' => 'Main program', 'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->copy()->addHours(5)->format('Y-m-d\TH:i')]],
        ]));
    }

    /** @return list<string> QR tokens of the invited guests */
    private function guests(Event $event, string $ownerKey, string $tierName, int $price, array $names): array
    {
        return $this->as($this->u($ownerKey), function () use ($event, $ownerKey, $tierName, $price, $names) {
            $tier = app(TierManager::class)->create($event, ['name' => $tierName, 'price' => $price, 'capacity' => max(50, count($names) + 20)]);
            app(EventManager::class)->transition($event->refresh(), 'publish');
            $member = $this->member(Organization::query()->find($event->organization_id), $ownerKey);
            $tokens = [];
            foreach ($names as $name) {
                $ticket = app(GuestList::class)->add($event, $tier, ['full_name' => $name, 'email' => Str::slug($name, '.')."@guests.{$this->domain}"], AdmissionType::Invited, $member, sendEmail: false);
                $tokens[] = app(GuestList::class)->qrToken($ticket);
            }

            return array_values(array_filter($tokens));
        });
    }

    /**
     * Seed shortcut: move the event $daysAgo into the past, check people in
     * during it, record walk-ins, then mark it completed.
     */
    private function happened(Event $event, string $ownerKey, int $daysAgo, int $hours, array $scans, array $walkIns, int $headcount): void
    {
        $start = now()->subDays($daysAgo);
        // Open the door "now" so the desks accept scans, then shift the records back.
        DB::table('event_sessions')->where('event_id', $event->id)->update(['starts_at' => now()->subMinutes(30), 'ends_at' => now()->addHours($hours)]);
        $this->as($this->u($ownerKey), function () use ($event, $ownerKey, $scans, $walkIns, $headcount) {
            $e = $event->refresh();
            $member = $this->member(Organization::query()->find($e->organization_id), $ownerKey);
            foreach ($scans as $token) {
                app(CheckInDesk::class)->scan($e, $member, $token, null);
            }
            foreach ($walkIns as $name) {
                app(WalkInDesk::class)->logName($e, $member, ['full_name' => $name], null, null);
            }
            if ($headcount > 0) {
                app(WalkInDesk::class)->headcount($e, $member, $headcount, null, null);
            }
        });
        DB::table('event_sessions')->where('event_id', $event->id)->update(['starts_at' => $start, 'ends_at' => $start->copy()->addHours($hours)]);
        foreach (DB::table('attendance_records')->where('event_id', $event->id)->orderBy('checked_in_at')->pluck('id') as $i => $id) {
            DB::table('attendance_records')->where('id', $id)->update(['checked_in_at' => $start->copy()->addMinutes(10 + $i * 7)]);
        }
        foreach (DB::table('walk_in_records')->where('event_id', $event->id)->pluck('id') as $i => $id) {
            DB::table('walk_in_records')->where('id', $id)->update(['recorded_at' => $start->copy()->addMinutes(45 + $i * 12)]);
        }
        $this->as($this->u($ownerKey), fn () => app(EventManager::class)->transition($event->refresh(), 'complete'));
        DB::table('events')->where('id', $event->id)->update(['created_at' => $start->copy()->subDays(30)]);
    }

    /** Seed shortcut: the event started an hour ago; some attendees are already in. */
    private function liveNow(Event $event, string $ownerKey, int $scans, int $headcount): void
    {
        DB::table('event_sessions')->where('event_id', $event->id)->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(6)]);
        $tokens = DB::table('tickets')->where('event_id', $event->id)->where('status', 'valid')->limit($scans)->pluck('id')
            ->map(fn ($id) => app(GuestList::class)->qrToken(Ticket::query()->find($id)))->filter()->values();
        $this->as($this->u($ownerKey), function () use ($event, $ownerKey, $tokens, $headcount) {
            $e = $event->refresh();
            app(EventManager::class)->transition($e, 'start');
            $member = $this->member(Organization::query()->find($e->organization_id), $ownerKey);
            foreach ($tokens as $token) {
                app(CheckInDesk::class)->scan($e->refresh(), $member, $token, null);
            }
            app(WalkInDesk::class)->headcount($e->refresh(), $member, $headcount, null, null);
        });
    }

    /**
     * A published public event with tiers on sale.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $tiers  name, price, capacity
     * @return array{0: Event, 1: list<TicketType>}
     */
    private function publicEvent(Organization $org, string $ownerKey, string $name, string $type, string $venue, int $capacity, int $inDays, array $tiers, string $about): array
    {
        return $this->as($this->u($ownerKey), function () use ($org, $ownerKey, $name, $type, $venue, $capacity, $inDays, $tiers, $about) {
            $start = now('Asia/Manila')->addDays($inDays)->setTime(16, 0);
            $event = app(EventManager::class)->create($org, $this->member($org, $ownerKey), [
                'name' => $name, 'event_type_id' => $this->typeId($type), 'description' => $about, 'visibility' => 'public', 'venue_name' => $venue,
                'overall_capacity' => $capacity,
                'sessions' => [['starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->copy()->addHours(6)->format('Y-m-d\TH:i')]],
            ]);
            $made = [];
            foreach ($tiers as [$tierName, $price, $cap]) {
                $made[] = app(TierManager::class)->create($event, ['name' => $tierName, 'price' => $price, 'capacity' => $cap, 'status' => 'on_sale']);
            }
            app(EventManager::class)->transition($event->refresh(), 'publish');

            return [$event->refresh(), $made];
        });
    }

    /** A buyer registers and (for paid tiers) pays through the demo checkout. */
    private function buy(string $buyerKey, Event $event, TicketType $tier, int $people): ?Payment
    {
        $buyer = $this->u($buyerKey);
        $attendees = collect(range(1, $people))->map(fn ($i) => ['full_name' => $i === 1 ? "{$buyer->first_name} {$buyer->last_name}" : "{$buyer->first_name}'s guest {$i}"])->all();
        $reg = $this->as($buyer, fn () => app(SelfRegistration::class)->register($buyer, $event->id, $tier->id, $attendees));
        if ($reg['status'] !== 'awaiting_payment') {
            return null;
        }
        $checkout = $this->as($buyer, fn () => app(Payments::class)->startCheckout($buyer, $reg['registration_id']));
        $payment = Payment::query()->findOrFail($checkout['payment_id']);

        return app(Payments::class)->settle($payment->paymongo_payment_intent_id, 'seed');
    }

    private function message(string $inquiryId, Organization $org, string $senderKey, string $body): void
    {
        $thread = DB::table('inquiry_organizations')->where('inquiry_id', $inquiryId)->where('organization_id', $org->id)->value('id');
        $this->as($this->u($senderKey), fn () => app(InquiryThreads::class)->send($this->u($senderKey), $thread, $body));
    }

    private function backdatePayments(string $eventId, int $daysAgo): void
    {
        $at = now()->subDays($daysAgo);
        $payments = DB::table('payments as p')->join('registrations as r', 'r.id', '=', 'p.registration_id')->where('r.event_id', $eventId)->pluck('p.id');
        DB::table('payments')->whereIn('id', $payments)->update(['paid_at' => $at, 'created_at' => $at]);
        DB::table('platform_fee_snapshots')->whereIn('payment_id', $payments)->update(['created_at' => $at]);
        DB::table('tickets')->where('event_id', $eventId)->update(['created_at' => $at]);
        DB::table('registrations')->where('event_id', $eventId)->update(['created_at' => $at]);
    }
}
