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
use App\Services\Events\EventActivities;
use App\Services\Events\EventManager;
use App\Services\Inquiries\InquiryManager;
use App\Services\Inquiries\InquiryThreads;
use App\Services\Inquiries\OrganizerInquiries;
use App\Services\Marketplace\EventTypeSuggestions;
use App\Services\Organizations\ApprovalWorkflow;
use App\Services\Organizations\OfferedServices;
use App\Services\Organizations\OrganizerOnboarding;
use App\Services\Payments\Earnings;
use App\Services\Payments\FakeGateway;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\Payments;
use App\Services\Payments\RefundReason;
use App\Services\Preparation\PreparationManager;
use App\Services\Quotations\ClientQuotations;
use App\Services\Quotations\OrganizerQuotations;
use App\Services\Reports\ReportSharing;
use App\Services\Reviews\ReviewManager;
use App\Services\Staff\EventTeam;
use App\Services\Staff\StaffManager;
use App\Services\Ticketing\GuestList;
use App\Services\Ticketing\SelfRegistration;
use App\Services\Ticketing\TierManager;
use App\Support\Database\DatabaseContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
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

    /** @var list<string> keys of the clients who signed up over the year */
    private array $crowd = [];

    /** @var array<string, \Carbon\Carbon> owner key => when their organization joined */
    private array $joined = [];

    private \Carbon\Carbon $started;

    private string $password = '';

    /** Set before prepare() on events that are moved into the past afterwards (their final date). */
    private ?CarbonImmutable $eventAt = null;

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
        Mail::fake();
        $this->password = $password;

        $this->line('Building the demo dataset (a year of use; takes a few minutes)…');
        $this->started = now();
        mt_srand(20261016); // same story every rebuild
        $this->catalog();
        $this->people($password);
        $this->crowd($password);
        $this->step('Organizers', fn () => $this->organizers());
        $this->team();
        $this->step('Bookings of the past year', fn () => $this->pastBookings());
        $this->step('Recent bookings', fn () => $this->bookings());
        $this->step('Inquiries that went nowhere', fn () => $this->pastInquiries());
        $this->step('Public ticketed events', fn () => $this->pastPublicEvents());
        $this->publicEvents();
        $this->platform();
        $this->payouts();
        $this->backdate();
        $this->call('nasery:demo:event');

        $this->newLine();
        $this->info('Demo ready. Every account below uses DEMO_PASSWORD.');
        $this->table(['Role', 'E-mail', 'Name'], collect($this->users)->reject(fn ($u, $key) => in_array(str($key)->after(':')->toString(), $this->crowd, true))->map(fn (User $u, $key) => [
            str($key)->before(':')->toString(), $u->email, trim("{$u->first_name} {$u->last_name}"),
        ])->values()->all());

        return self::SUCCESS;
    }

    private function step(string $what, Closure $fn): void
    {
        $t = microtime(true);
        $fn();
        $this->line(sprintf('  %s: done (%.0f s)', $what, microtime(true) - $t));
    }

    // ---- Accounts ------------------------------------------------------------

    private function people(string $password): void
    {
        $this->users['admin:admin'] = $this->user('admin', 'Dana', 'Admin', AccountType::Admin, $password);

        foreach ([
            'maria' => ['Maria', 'Santos'], 'carlo' => ['Carlo', 'Reyes'], 'liza' => ['Liza', 'Tan'],
            'jomar' => ['Jomar', 'Uy'], 'rhea' => ['Rhea', 'Lim'],
            'nina' => ['Nina', 'Cabrera'], 'oscar' => ['Oscar', 'Lumapas'], 'tess' => ['Tess', 'Bacalso'], 'luz' => ['Luz', 'Dalisay'],
            'ramon' => ['Ramon', 'Ilagan'], 'benjo' => ['Benjo', 'Sarmiento'], 'cris' => ['Cris', 'Padilla'],
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
        // key => owner, display name, legal name, location, lat, lng, about, services (name => base price), status, plan, months on NaSeRy
        $specs = [
            'dream' => ['maria', 'Davao Dream Events', 'Davao Dream Events Co.', 'Poblacion, Davao City', 7.0731, 125.6128,
                'Full-service weddings, debuts and corporate events across Davao since 2015. One coordinator from first meeting to last dance.',
                ['Event Coordination' => 45000, 'Venue Styling' => 30000, 'Hosting / Emcee' => 8000, 'Lights & Sound' => 18000], ApprovalStatus::Approved, 'pro', 13],
            'grand' => ['carlo', 'Mindanao Grand Occasions', 'Mindanao Grand Occasions Inc.', 'Lanang, Davao City', 7.1007, 125.6480,
                'Corporate launches, conferences and family celebrations with in-house catering.',
                ['Catering' => 650, 'Event Coordination' => 38000, 'Tents, Tables & Chairs' => 12000], ApprovalStatus::Approved, 'starter', 12],
            'samal' => ['liza', 'Samal Island Weddings', 'Samal Island Weddings', 'Island Garden City of Samal', 7.0732, 125.7094,
                'Beach weddings and sunset celebrations on Samal Island.',
                ['Event Coordination' => 55000, 'Photo & Video' => 25000], ApprovalStatus::Approved, 'free', 11],
            'matina' => ['nina', 'Matina Kitchen Catering', 'Matina Kitchen Food Services', 'Matina, Davao City', 7.0598, 125.5967,
                'Home-style Davao buffets, lechon and dessert tables for 30 to 500 guests.',
                ['Catering' => 550, 'Cakes & Desserts' => 6500], ApprovalStatus::Approved, 'starter', 10],
            'lanang' => ['oscar', 'Lanang Lights & Sound', 'Lanang Lights and Sound Rentals', 'Lanang, Davao City', 7.1050, 125.6400,
                'Sound systems, stage lighting and LED walls with an operator, from intimate dinners to concerts.',
                ['Lights & Sound' => 12000], ApprovalStatus::Approved, 'pro', 9],
            'bajada' => ['tess', 'Bajada Photo & Film', 'Bajada Photo & Film Studio', 'Bajada, Davao City', 7.0890, 125.6110,
                'Photo and video coverage, same-day edits and photobooths.',
                ['Photo & Video' => 18000], ApprovalStatus::Approved, 'starter', 8],
            'calinan' => ['luz', 'Calinan Garden Venue', 'Calinan Garden Resort and Events Place', 'Calinan, Davao City', 7.1910, 125.4530,
                'A garden venue among the durian farms of Calinan, with styling and buffet packages.',
                ['Venue Styling' => 25000, 'Catering' => 600], ApprovalStatus::Approved, 'starter', 7],
            'tagum' => ['ramon', 'Tagum Events Hub', 'Tagum Events Hub', 'Tagum City, Davao del Norte', 7.4478, 125.8078,
                'Coordination and rentals for Tagum, Panabo and Carmen.',
                ['Event Coordination' => 30000, 'Tents, Tables & Chairs' => 8000], ApprovalStatus::Approved, 'free', 5],
            'ushers' => ['benjo', 'Davao Ushers & Security', 'Davao Ushers and Security Services', 'Sasa, Davao City', 7.1180, 125.6560,
                'Trained ushers, ticket checkers and licensed guards for events of any size.',
                ['Security & Ushers' => 1200], ApprovalStatus::Approved, 'free', 4],
            'toril' => ['jomar', 'Toril Party Planners', 'Toril Party Planners', 'Toril, Davao City', 7.0190, 125.4978,
                'Kiddie parties and debuts in the south of Davao.', ['Event Coordination' => 20000], ApprovalStatus::Pending, 'free', 0],
            'buhangin' => ['rhea', 'Buhangin Balloons & Co.', 'Buhangin Balloons & Co.', 'Buhangin, Davao City', 7.1135, 125.6147,
                'Balloon styling and party backdrops.', ['Venue Styling' => 9000], ApprovalStatus::UnderReview, 'free', 0],
            'quick' => ['cris', 'Quick Party PH', 'Quick Party PH', 'Davao City', 7.0700, 125.6000,
                'Party packages.', ['Event Coordination' => 15000], ApprovalStatus::Rejected, 'free', 3],
        ];

        // years, service radius (km), event tags, specialties, banner color, website (example.com: reserved, goes nowhere)
        $details = [
            'dream' => ['5–10 years', 40, ['Wedding', 'Debut', 'Corporate Event'], ['Full Event Coordination', 'Decor & Styling'], '#be123c', 'https://davaodreamevents.example.com'],
            'grand' => ['10+ years', 60, ['Corporate Event', 'Conference', 'Seminar', 'Birthday Party', 'Christening'], ['Catering Services', 'Full Event Coordination', 'Venue Sourcing'], '#1d4ed8', null],
            'samal' => ['3–5 years', 30, ['Wedding'], ['Decor & Styling', 'Photography & Videography'], '#15803d', null],
            'matina' => ['5–10 years', 35, ['Wedding', 'Birthday Party', 'Christening', 'Debut', 'Reunion'], ['Catering Services'], '#b45309', null],
            'lanang' => ['3–5 years', 80, ['Concert', 'Corporate Event', 'Wedding'], ['Lights & Sound'], '#4338ca', 'https://lananglights.example.com'],
            'bajada' => ['3–5 years', 60, ['Wedding', 'Debut', 'Birthday Party'], ['Photography & Videography'], '#0f766e', null],
            'calinan' => ['5–10 years', 25, ['Wedding', 'Christening', 'Corporate Event', 'Reunion'], ['Venue Sourcing', 'Decor & Styling', 'Catering Services'], '#4d7c0f', null],
            'tagum' => ['1–3 years', 50, ['Birthday Party', 'Christening', 'Wedding'], ['Full Event Coordination'], '#c2410c', null],
            'ushers' => ['5–10 years', 60, ['Concert', 'Conference', 'Seminar', 'Corporate Event'], ['Security & Crowd Control'], '#334155', null],
        ];

        // 0052: price options meant for certain event types (option name => tags); unlisted = every event type.
        $optionTags = [
            'dream' => ['Full wedding coordination' => ['Wedding'], 'Garden styling' => ['Wedding', 'Debut'], 'Grand ballroom styling' => ['Wedding', 'Debut', 'Corporate Event']],
            'grand' => ['Conference setup and coordination' => ['Conference', 'Seminar', 'Corporate Event'], 'International buffet' => ['Corporate Event', 'Conference']],
            'samal' => ['Beach wedding package' => ['Wedding'], 'Sunset proposal setup' => ['Wedding']],
            'matina' => ['Premium buffet (7 dishes)' => ['Wedding', 'Debut', 'Reunion']],
            'lanang' => ['Concert sound and lights' => ['Concert']],
        ];

        // Price list (B: 0048): service => [name, unit, price, unit label, min qty, max qty, min guests]
        $prices = [
            'dream' => [
                'Event Coordination' => [['Full wedding coordination', 'flat', 45000], ['Day-of coordination only', 'flat', 25000]],
                'Venue Styling' => [['Garden styling', 'per_guest', 350, null, null, null, 50], ['Grand ballroom styling', 'per_guest', 550, null, null, null, 80]],
                'Hosting / Emcee' => [['Emcee', 'per_hour', 2000, null, 3, 8]],
                null => [['Same-day edit video', 'flat', 15000]],
            ],
            'grand' => [
                'Catering' => [['Filipino buffet', 'per_guest', 650, null, null, null, 30], ['International buffet', 'per_guest', 950, null, null, null, 50]],
                'Event Coordination' => [['Conference setup and coordination', 'flat', 25000]],
                'Tents, Tables & Chairs' => [['Round tables with linen', 'per_unit', 350, 'table', 5, 60]],
            ],
            'samal' => [
                'Event Coordination' => [['Beach wedding package', 'flat', 55000], ['Sunset proposal setup', 'flat', 18000]],
                'Photo & Video' => [['Photo and video coverage', 'per_hour', 3500, null, 4, 12]],
            ],
            'matina' => [
                'Catering' => [['Davao buffet (5 dishes)', 'per_guest', 550, null, null, null, 30], ['Premium buffet (7 dishes)', 'per_guest', 780, null, null, null, 50]],
                'Cakes & Desserts' => [['Dessert table', 'flat', 6500]],
                null => [['Whole lechon', 'per_unit', 9500, 'lechon', 1, 6], ['Extra serving staff', 'per_unit', 800, 'waiter', 1, 20]],
            ],
            'lanang' => [
                'Lights & Sound' => [['Basic sound system', 'flat', 12000], ['Concert sound and lights', 'per_day', 45000, null, 1, 3]],
                null => [['Stage lighting operator', 'per_hour', 2500, null, 3, 12], ['LED wall (3x2 m)', 'per_day', 18000, null, 1, 3]],
            ],
            'bajada' => [
                'Photo & Video' => [['Photo coverage', 'per_hour', 3500, null, 2, 10], ['Photo and video coverage', 'per_hour', 5500, null, 3, 10]],
                null => [['Same-day edit video', 'flat', 15000], ['Photobooth with prints', 'per_hour', 2000, null, 2, 6]],
            ],
            'calinan' => [
                'Venue Styling' => [['Garden venue with styling', 'per_day', 35000, null, 1, 2]],
                'Catering' => [['Garden buffet', 'per_guest', 600, null, null, null, 40]],
            ],
            'tagum' => [
                'Event Coordination' => [['Party coordination', 'flat', 30000]],
                'Tents, Tables & Chairs' => [['Monobloc chairs', 'per_unit', 25, 'chair', 50, 1000], ['Tables', 'per_unit', 150, 'table', 5, 100], ['Tent (5x5 m)', 'per_day', 3500, null, 1, 5]],
            ],
            'ushers' => [
                'Security & Ushers' => [['Usher (8-hour shift)', 'per_unit', 1200, 'usher', 2, 40], ['Licensed security guard (8 hours)', 'per_unit', 1500, 'guard', 2, 30]],
            ],
        ];
        // Service charge %, VAT %, note under the estimate
        $estimates = [
            'dream' => [10, 12, 'Out-of-town events add a transport fee.'],
            'grand' => [10, 12, null],
            'matina' => [0, 0, 'Prices include serving staff for up to 4 hours.'],
            'lanang' => [0, 12, 'Ingress the day before is free for events in Davao City.'],
            'calinan' => [5, 0, null],
        ];

        foreach ($specs as $key => [$ownerKey, $name, $legal, $location, $lat, $lng, $about, $services, $status, $plan, $months]) {
            $owner = $this->u($ownerKey);
            $org = $this->as($owner, fn () => app(OrganizerOnboarding::class)->create($owner, [
                'legal_name' => $legal, 'display_name' => $name,
                'profile' => ['business_name' => $name, 'description' => $about, 'location_text' => $location, 'latitude' => $lat, 'longitude' => $lng,
                    'contact_email' => "hello@{$key}.{$this->domain}", 'contact_phone' => '082 '.random_int(200, 299).' '.random_int(1000, 9999)],
            ]));

            if ($status === ApprovalStatus::Rejected) {
                $this->as($this->u('admin'), fn () => app(ApprovalWorkflow::class)->decide($org, ApprovalStatus::Rejected,
                    'The business permit photo is unreadable and the DTI name does not match. Please upload a clear copy and apply again.'));
            } elseif ($status !== ApprovalStatus::Pending) {
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
            if (isset($estimates[$key])) {
                [$charge, $tax, $note] = $estimates[$key];
                DB::table('organization_profiles')->where('organization_id', $org->id)->update([
                    'estimate_service_charge_percent' => $charge, 'estimate_tax_percent' => $tax, 'estimate_note' => $note,
                ]);
            }

            $offered = [];
            foreach ($services as $service => $price) {
                $serviceId = DB::table('services')->whereNull('deleted_at')->where('name', $service)->value('id');
                $this->as($owner, fn () => app(OfferedServices::class)->create($org, ['service_id' => $serviceId, 'base_price' => $price, 'is_active' => true]));
                $offered[$service] = DB::table('organization_services')->where('organization_id', $org->id)->where('service_id', $serviceId)->whereNull('deleted_at')->value('id');
            }
            if (isset($prices[$key])) {
                $this->as($owner, function () use ($org, $prices, $key, $offered, $optionTags) {
                    $sort = 0;
                    foreach ($prices[$key] as $service => $options) {
                        foreach ($options as $o) {
                            [$label, $unit, $price] = $o;
                            $org->priceOptions()->create([
                                'organization_service_id' => $service === '' ? null : ($offered[$service] ?? null),
                                'name' => $label, 'pricing_unit' => $unit, 'price' => $price, 'is_active' => true, 'sort_order' => $sort++,
                                'unit_label' => $o[3] ?? null, 'min_quantity' => $o[4] ?? null, 'max_quantity' => $o[5] ?? null, 'min_guests' => $o[6] ?? null,
                                'event_tags' => $optionTags[$key][$label] ?? [],
                            ]);
                        }
                    }
                });
            }

            $this->plan($org, $plan, $months);
            // Joined NaSeRy $months ago (pending applications: this week).
            $joined = $months > 0 ? now()->subMonths($months)->subDays(mt_rand(0, 20)) : now()->subDays(mt_rand(1, 6));
            DB::table('organizations')->where('id', $org->id)->update(['created_at' => $joined, 'updated_at' => $joined]);
            DB::table('organization_members')->where('organization_id', $org->id)->update(['created_at' => $joined]);
            $this->joined[$ownerKey] = $joined;
            $this->orgs[$key] = $org->refresh();
        }
    }

    /**
     * Seed shortcut: an active paid plan bought when the organizer joined, with
     * one paid invoice per billing period since (monthly) or per year (yearly).
     */
    private function plan(Organization $org, string $slug, int $months): void
    {
        if ($slug === 'free') {
            return;
        }
        $plan = DB::table('plans')->where('slug', $slug)->first();
        $cycle = $slug === 'pro' ? 'yearly' : 'monthly';
        $step = $cycle === 'yearly' ? 12 : 1;
        $amount = $cycle === 'yearly' ? $plan->price_yearly : $plan->price_monthly;
        // Upgraded a month after joining.
        $first = now()->subMonths(max(1, $months - 1))->startOfDay();
        $periods = [];
        for ($start = $first->copy(); $start->lte(now()); $start = $start->copy()->addMonths($step)) {
            $periods[] = [$start, $start->copy()->addMonths($step)];
        }
        [$curStart, $curEnd] = end($periods);
        $subId = (string) Str::uuid7();
        DB::table('subscriptions')->insert(['id' => $subId, 'organization_id' => $org->id, 'plan_id' => $plan->id, 'billing_cycle' => $cycle, 'status' => 'active',
            'current_period_start' => $curStart, 'current_period_end' => $curEnd, 'created_at' => $first, 'updated_at' => $curStart]);
        foreach ($periods as [$start, $end]) {
            $invoiceId = (string) Str::uuid7();
            DB::table('subscription_invoices')->insert(['id' => $invoiceId, 'subscription_id' => $subId, 'amount' => $amount, 'billing_period_start' => $start,
                'billing_period_end' => $end, 'status' => 'paid', 'issued_at' => $start, 'created_at' => $start]);
            DB::table('payments')->insert(['id' => (string) Str::uuid7(), 'subscription_invoice_id' => $invoiceId, 'paymongo_payment_intent_id' => 'demo_'.Str::lower(Str::random(20)),
                'amount' => $amount, 'currency' => 'PHP', 'status' => 'paid', 'paid_at' => $start, 'created_at' => $start, 'updated_at' => $start]);
        }
    }

    /**
     * Staff join the way real ones do: the owner invites a new e-mail with a
     * role, NaSeRy creates the account and e-mails a set-up link, and opening
     * it (choosing a password) joins the team. Plus every state the Team page
     * can show: an invitation not opened yet, someone removed, someone who left.
     */
    private function team(): void
    {
        $this->hire('dream', 'ken', 'Ken', 'Aquino', 'coordinator', daysAgo: 300);
        $this->hire('dream', 'aira', 'Aira', 'Mendoza', 'checkin', daysAgo: 210);
        $this->hire('dream', 'paolo', 'Paolo', 'Rivera', 'finance', daysAgo: 330);
        $this->hire('grand', 'jessa', 'Jessa', 'Villamor', 'coordinator', daysAgo: 280);
        $this->hire('grand', 'mark', 'Mark', 'Ong', 'checkin', daysAgo: 150);

        // Left on their own a month and a half ago (Paula's dashboard: "You're no longer on Davao Dream Events' team").
        $paula = $this->hire('dream', 'paula', 'Paula', 'Gonzaga', 'coordinator', daysAgo: 320);
        $this->as($paula, fn () => app(StaffManager::class)->leave($paula));
        $this->endedAt('dream', $paula, daysAgo: 45);

        // Removed by the owner two months ago.
        $leo = $this->hire('grand', 'leo', 'Leo', 'Panganiban', 'checkin', daysAgo: 260);
        $grand = $this->orgs['grand'];
        $this->as($this->u('carlo'), fn () => app(StaffManager::class)->remove($grand, $this->u('carlo'), $this->member($grand, 'leo')));
        $this->endedAt('grand', $leo, daysAgo: 60);

        // Invited two days ago, hasn't opened the link yet (Team page: "Waiting for set-up", with Resend).
        $dream = $this->orgs['dream'];
        $maria = $this->u('maria');
        $this->as($maria, function () use ($dream, $maria) {
            $role = app(StaffManager::class)->presetRole($dream, $maria, 'sales');
            app(StaffManager::class)->invite($dream, $maria, "rina@{$this->domain}", $role, 'Rina', 'Cortez');
        });
        $at = now()->subDays(2);
        DB::table('organization_members')->where('organization_id', $dream->id)->where('status', 'invited')->update(['created_at' => $at, 'updated_at' => $at]);
        DB::table('users')->where('email', "rina@{$this->domain}")->update(['created_at' => $at, 'updated_at' => $at]);
    }

    /** Invite a new e-mail to $orgKey's team, then "open the link": set the password and join. */
    private function hire(string $orgKey, string $key, string $first, string $last, string $preset, int $daysAgo): User
    {
        $org = $this->orgs[$orgKey];
        $owner = $this->u($this->ownerOf($orgKey));
        $email = "{$key}@{$this->domain}";
        $this->as($owner, function () use ($org, $owner, $email, $preset, $first, $last) {
            $role = app(StaffManager::class)->presetRole($org, $owner, $preset);
            app(StaffManager::class)->invite($org, $owner, $email, $role, $first, $last);
        });
        // Seed shortcut for "opened the e-mail link and chose a password" (the link proves the mailbox).
        $user = User::query()->where('email', $email)->firstOrFail();
        $user->forceFill(['password_hash' => $this->password, 'email_verified_at' => now(), 'phone' => '0917 '.random_int(100, 999).' '.random_int(1000, 9999)])->save();
        app(StaffManager::class)->completeSetup($user->refresh());
        $this->users["organizer:{$key}"] = $user->refresh();

        $joined = now()->subDays($daysAgo);
        DB::table('organization_members')->where('organization_id', $org->id)->where('user_id', $user->id)
            ->update(['created_at' => $joined->copy()->subDays(1), 'joined_at' => $joined, 'updated_at' => $joined]);

        return $user;
    }

    /** Seed shortcut: the removal / leaving happened $daysAgo. */
    private function endedAt(string $orgKey, User $user, int $daysAgo): void
    {
        $at = now()->subDays($daysAgo);
        DB::table('organization_members')->where('organization_id', $this->orgs[$orgKey]->id)->where('user_id', $user->id)
            ->update(['deactivated_at' => $at, 'updated_at' => $at]);
    }

    // ---- Client bookings (inquiry → quotation → event) ------------------------

    private function bookings(): void
    {
        [$dream, $grand, $samal] = [$this->orgs['dream'], $this->orgs['grand'], $this->orgs['samal']];

        // 1. Ella's wedding with Davao Dream: completed, guests checked in, report shared, 5★ with a reply.
        $wedding = $this->won('ella', 'Wedding', 120, [$dream], $dream, 'maria', 'Garden Wedding Package', 185000, daysAgo: 75, eventName: 'Reyes–Santos Garden Wedding');
        $weddingEvent = $this->eventFrom($dream, 'maria', $wedding, 'Reyes–Santos Garden Wedding', 'Pearl Farm Pavilion, Davao City', 8);
        $this->eventAt = CarbonImmutable::now('Asia/Manila')->subDays(21);
        $this->prepare($weddingEvent, 'dream');
        $guests = $this->guests($weddingEvent, 'maria', TicketType::GUEST_LIST, 0, ['Lara Reyes', 'Jun Santos', 'Mila Cruz', 'Ramon Reyes', 'Tess Lim', 'Oscar Tan', 'Nina Uy', 'Paul Go', 'Rina Sy', 'Leo Chua']);
        $this->happened($weddingEvent, 'maria', daysAgo: 21, hours: 6, scans: array_slice($guests, 0, 8), walkIns: ['Tita Nena Reyes'], headcount: 2);
        $this->as($this->u('maria'), fn () => app(ReportSharing::class)->share($weddingEvent->refresh(), $this->u('maria'), 'Thank you for celebrating with us!', false));
        $review = $this->as($this->u('ella'), fn () => app(ReviewManager::class)->create($this->u('ella'), $weddingEvent->refresh(), 5,
            'Maria and her team handled everything, from the rain plan to the last dance. Our guests are still talking about the styling.'));
        $this->as($this->u('maria'), fn () => app(ReviewManager::class)->reply($dream, $this->member($dream, 'maria'), $review['id'], 'Thank you, Ella! It was an honor to be part of your day.'));
        // After the accounting: 15 more guests and an extra band hour than the accepted offer.
        $this->as($this->u('maria'), fn () => app(EventManager::class)->setAgreedAmount($weddingEvent->refresh(), 197500, 'Added 15 guests and one more hour of the band on the day.'));

        // 2. Andrea's christening with Mindanao Grand: completed, 3★ review that the organizer reported.
        $christening = $this->won('andrea', 'Christening', 60, [$grand], $grand, 'carlo', 'Christening Reception', 48000, daysAgo: 40, eventName: 'Baby Liam\'s Christening');
        $christeningEvent = $this->eventFrom($grand, 'carlo', $christening, 'Villanueva Christening Reception', 'Grand Regal Hotel, Lanang', 6);
        $this->eventAt = CarbonImmutable::now('Asia/Manila')->subDays(9);
        $this->prepare($christeningEvent, 'grand');
        $cGuests = $this->guests($christeningEvent, 'carlo', TicketType::GUEST_LIST, 0, ['Ana Villanueva', 'Ben Cruz', 'Cora Diaz', 'Dino Lopez', 'Edna Sy', 'Fely Uy']);
        $this->happened($christeningEvent, 'carlo', daysAgo: 9, hours: 4, scans: array_slice($cGuests, 0, 5), walkIns: [], headcount: 1);
        $cReview = $this->as($this->u('andrea'), fn () => app(ReviewManager::class)->create($this->u('andrea'), $christeningEvent->refresh(), 3,
            'Nice venue, but the food came out 40 minutes late and the host mispronounced our baby\'s name.'));
        $this->as($this->u('carlo'), fn () => app(ReviewManager::class)->report($this->u('carlo'), $cReview['id'], 'The delay was caused by the client extending the ceremony; we have the timeline.'));
        $this->as($this->u('carlo'), fn () => app(EventManager::class)->setAgreedAmount($christeningEvent->refresh(), 45000, 'Discount for the late food service.'));

        // 3. Joshua's 18th birthday with Mindanao Grand: upcoming, preparation under way.
        $birthday = $this->won('joshua', 'Birthday Party', 80, [$grand, $dream], $grand, 'carlo', 'Debut & Birthday Package', 62000, daysAgo: 12, loserOrg: $dream, loserOwner: 'maria', loserAmount: 70000, eventName: 'Joshua\'s 18th Birthday');
        $birthdayEvent = $this->eventFrom($grand, 'carlo', $birthday, 'Joshua\'s 18th Birthday', 'Seda Abreeza, Davao City', 5, inDays: 25);
        $this->as($this->u('carlo'), fn () => app(EventManager::class)->transition($birthdayEvent->refresh(), 'publish'));
        $this->prepare($birthdayEvent, 'grand');

        // 3b. Bea's wedding with Davao Dream in 40 days, and a corporate night in 9 days: the team is busy.
        $beaWedding = $this->won('bea', 'Wedding', 150, [$dream], $dream, 'maria', 'Grand Ballroom Wedding', 215000, daysAgo: 70, eventName: 'Santos–Lim Wedding');
        $beaEvent = $this->eventFrom($dream, 'maria', $beaWedding, 'Santos–Lim Wedding', 'Marco Polo Davao', 8, inDays: 40);
        $this->as($this->u('maria'), fn () => app(EventManager::class)->transition($beaEvent->refresh(), 'publish'));
        $this->prepare($beaEvent, 'dream');
        $corpNight = $this->won('dana', 'Corporate Event', 180, [$dream, $grand], $dream, 'maria', 'Corporate Anniversary Night', 260000, daysAgo: 65,
            loserOrg: $grand, loserOwner: 'carlo', loserAmount: 245000, eventName: 'Mintal Agri 25th Anniversary Night');
        $corpEvent = $this->eventFrom($dream, 'maria', $corpNight, 'Mintal Agri 25th Anniversary Night', 'The Pinnacle Hotel', 8, inDays: 9);
        $this->as($this->u('maria'), fn () => app(EventManager::class)->transition($corpEvent->refresh(), 'publish'));
        $this->prepare($corpEvent, 'dream');

        // 4. Miguel's corporate event: two bids in, still deciding, messages both ways.
        $corporate = $this->inquiry('miguel', 'Corporate Event', 200, [$dream, $grand], daysAgo: 4, eventName: 'Northwind Davao 10th Anniversary');
        $this->quote($dream, 'maria', $corporate, 'Corporate Anniversary Night', 240000);
        $this->quote($grand, 'carlo', $corporate, 'Conference + Dinner', 215000);
        $this->message($corporate, $dream, 'miguel', 'Hi! Can you handle a stage program with a live band for our anniversary?');
        $this->message($corporate, $dream, 'maria', 'Yes, we work with two bands in Davao. The package includes a 3-hour program with an emcee.');
        $this->message($corporate, $grand, 'miguel', 'Is the buffet halal-friendly? A few of our guests need that.');

        // 5. Sofia's debut: Samal quoted, Davao Dream declined (fully booked).
        $debut = $this->inquiry('sofia', 'Debut', 150, [$samal, $dream], daysAgo: 2, eventName: 'Sofia\'s Sunset Debut');
        $this->quote($samal, 'liza', $debut, 'Sunset Debut on the Beach', 98000);
        $this->as($this->u('maria'), fn () => app(OrganizerInquiries::class)->decline($dream, Inquiry::query()->findOrFail($debut), 'We are fully booked on that date. Congratulations in advance!'));

        // 6. Rafael's family reunion dinner (months ago): Davao Dream lost to Mindanao Grand (analytics history).
        $this->won('rafael', 'Reunion', 50, [$dream, $grand], $grand, 'carlo', 'Family Reunion Dinner', 42000, daysAgo: 100, loserOrg: $dream, loserOwner: 'maria', loserAmount: 55000);
    }

    // ---- Public ticketed events ------------------------------------------------

    private function publicEvents(): void
    {
        [$dream, $grand, $samal] = [$this->orgs['dream'], $this->orgs['grand'], $this->orgs['samal']];

        // Kadayawan Street Food Festival (Davao Dream): on sale, many buyers, one force-majeure refund (fee claim).
        [$fest, $tiers] = $this->publicEvent($dream, 'maria', 'Kadayawan Street Food Festival', 'Festival', 'Magsaysay Park, Davao City', 300, 6,
            [['General Admission', 250, 200], ['VIP Lounge', 800, 50], ['Kids (12 and under)', 0, 50]],
            'Davao\'s favorite food stalls, live music and a durian eating contest.');
        $this->prepare($fest, 'dream', public: true);
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
        $this->prepare($summit, 'grand', public: true);
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
        // Event types organizers suggested (0054): one approved, one merged into Birthday Party, one waiting on Event Tags.
        $types = app(EventTypeSuggestions::class);
        $types->suggest($this->orgs['bajada'], $this->u('tess'), 'Gender Reveal', true);
        $types->suggest($this->orgs['tagum'], $this->u('ramon'), 'Kiddie Party', true);
        $types->suggest($this->orgs['grand'], $this->u('carlo'), 'Product Launch', true);
        foreach (['Gender Reveal' => ['approve', null, 'Added. Thanks for the suggestion!'], 'Kiddie Party' => ['merge', 'Birthday Party', 'Kiddie parties are listed under Birthday Party.']] as $name => [$decision, $into, $note]) {
            $id = DB::table('event_type_suggestions')->where('name', $name)->value('id');
            $types->decide($admin, $id, $decision, $decision === 'approve' ? $name : null, $into ? $this->typeId($into) : null, $note);
        }

        // Mindanao Grand asked NaSeRy support to look at their workspace (waiting for an admin).
        $this->as($this->u('carlo'), fn () => app(AccessRequests::class)->invite($this->u('carlo'), $this->orgs['grand'],
            'Our Workshop Pass sales look lower than our bank deposits; please check the payment list.', 24));
    }

    // ---- A year of ordinary use ----------------------------------------------------

    private const FIRST = ['Angela', 'Mark', 'Kristine', 'John Paul', 'Patricia', 'Jerome', 'Camille', 'Vincent', 'Nicole', 'Ralph', 'Hazel', 'Dennis',
        'Joy', 'Arnel', 'Mae', 'Christian', 'Princess', 'Ryan', 'Lovely', 'Jayson', 'Shiela', 'Kevin', 'Rowena', 'Neil', 'Grace', 'Ivan', 'Jasmine',
        'Marvin', 'Trisha', 'Allan', 'Charmaine', 'Rey', 'Diane', 'Francis', 'Bianca', 'Nestor'];

    private const LAST = ['Abellana', 'Bacus', 'Cabahug', 'Dagohoy', 'Escaño', 'Fernandez', 'Galicia', 'Hernandez', 'Ignacio', 'Jumawan', 'Lumbab',
        'Macaraeg', 'Nacario', 'Ocampo', 'Pacana', 'Quiñones', 'Rosales', 'Sabanal', 'Tabada', 'Ubaldo', 'Valdez', 'Yap', 'Zamora', 'Alcantara',
        'Belarmino', 'Corpuz', 'Dizon', 'Estrada', 'Florendo', 'Gutierrez'];

    private const REVIEWS = [
        5 => ['Everything went exactly as planned. The team was on top of every detail and our guests loved it.',
            'Super responsive from the first inquiry. They even fixed a last-minute venue change without extra charge.',
            'Worth every peso. The styling was beautiful and the program ran on time.',
            'Our family could actually enjoy the day because they handled everything. Highly recommended!'],
        4 => ['Very good overall. Food and setup were great; the program started a little late.',
            'Professional and friendly team. A few small hiccups with the sound but they fixed it quickly.',
            'Good value for the price. Would book again for our next event.'],
        3 => ['Okay experience. The setup was nice but communication before the event was slow.',
            'Decent, but a few items in the package were missing on the day.'],
        2 => ['The food ran out before all the guests were served and the team arrived late.'],
    ];

    /** Clients who signed up over the year; their accounts look like ordinary users. */
    private function crowd(string $password): void
    {
        foreach (self::FIRST as $i => $first) {
            $last = self::LAST[($i * 7) % count(self::LAST)];
            $key = Str::slug($first, '').'.'.Str::slug($last, '');
            $this->users["client:{$key}"] = $this->user($key, $first, $last, AccountType::Client, $password);
            $this->crowd[] = $key;
        }
    }

    private function pick(array $items): mixed
    {
        return $items[mt_rand(0, count($items) - 1)];
    }

    /** @return list<string> */
    private function sample(array $items, int $n): array
    {
        $keys = array_keys($items);
        usort($keys, fn () => mt_rand(-1, 1));

        return array_map(fn ($k) => $items[$k], array_slice($keys, 0, min($n, count($items))));
    }

    /** @return list<string> distinct guest names */
    private function names(int $n): array
    {
        $names = [];
        while (count($names) < $n) {
            $names[$this->pick(self::FIRST).' '.$this->pick(self::LAST)] = true;
        }

        return array_keys($names);
    }

    /**
     * Past private bookings across the last 12 months: inquiry → bids →
     * accepted quotation → event → guests checked in → (usually) a review.
     */
    private function pastBookings(): void
    {
        // event type => [guests min, max, price min, max, organizers that do it, event-name pattern]
        $kinds = [
            'Wedding' => [80, 250, 120000, 320000, ['dream', 'samal', 'calinan', 'bajada', 'matina'], '%s–%s Wedding'],
            'Debut' => [80, 200, 60000, 150000, ['dream', 'tagum', 'bajada', 'lanang'], '%s\'s 18th Birthday'],
            'Birthday Party' => [30, 120, 25000, 80000, ['grand', 'tagum', 'matina'], '%s\'s Birthday Party'],
            'Christening' => [30, 90, 25000, 60000, ['matina', 'calinan', 'grand'], 'Baby %s\'s Christening'],
            'Corporate Event' => [60, 400, 80000, 350000, ['grand', 'dream', 'lanang'], '%s Company Year-End Party'],
            'Conference' => [100, 500, 120000, 400000, ['grand', 'lanang'], 'Mindanao %s Summit'],
        ];
        $venues = ['Marco Polo Davao', 'Seda Abreeza', 'The Pinnacle Hotel', 'Apo View Hotel', 'Calinan Garden Venue', 'Pearl Farm Pavilion',
            'Waterfront Insular Hotel', 'Acacia Hotel Davao', 'Eden Nature Park', 'Grand Regal Hotel', 'SMX Convention Center', 'Ritz Hotel at Garden Oases'];
        $summits = ['Agri-Tech', 'Fintech', 'Tourism', 'Startup', 'Health'];

        $clients = $this->crowd;
        for ($i = 0; $i < 34; $i++) {
            $type = array_keys($kinds)[$i % count($kinds)];
            [$gMin, $gMax, $pMin, $pMax, $orgKeys, $pattern] = $kinds[$type];
            $clientKey = $clients[$i % count($clients)];
            $client = $this->u($clientKey);
            $eventDaysAgo = max(10, 355 - $i * 10 + mt_rand(-3, 3));
            // Only organizers already on NaSeRy when the inquiry was posted can be booked.
            $onBoard = array_values(array_filter($orgKeys, fn ($k) => ($this->joined[$this->ownerOf($k)] ?? now())->lt(now()->subDays($eventDaysAgo + 85))));
            $onBoard = $onBoard ?: ['dream', 'grand'];
            $winnerKey = $this->pick($onBoard);
            $winner = $this->orgs[$winnerKey];
            $loserKey = collect($onBoard)->reject(fn ($k) => $k === $winnerKey)->first();
            $amount = (int) round(mt_rand($pMin, $pMax), -3);
            $guests = mt_rand($gMin / 10, $gMax / 10) * 10;
            $title = match ($type) {
                'Wedding' => sprintf($pattern, $client->last_name, $this->pick(self::LAST)),
                'Conference' => sprintf($pattern, $this->pick($summits)),
                'Corporate Event' => sprintf($pattern, $this->pick(['Abacus', 'Davao Light', 'Mintal Agri', 'Sasa Ports', 'Lanang Tech', 'Ecoland Foods'])),
                'Christening' => sprintf($pattern, $this->pick(self::FIRST)),
                default => sprintf($pattern, $client->first_name),
            };

            $quotation = $this->won($clientKey, $type, $guests, $loserKey ? [$winner, $this->orgs[$loserKey]] : [$winner], $winner, $this->ownerOf($winnerKey),
                "{$type} Package", $amount, daysAgo: $eventDaysAgo + mt_rand(35, 80),
                loserOrg: $loserKey ? $this->orgs[$loserKey] : null, loserOwner: $loserKey ? $this->ownerOf($loserKey) : null, loserAmount: (int) round($amount * mt_rand(95, 125) / 100, -3));
            $event = $this->eventFrom($winner, $this->ownerOf($winnerKey), $quotation, $title, $this->pick($venues), 8);
            $this->eventAt = CarbonImmutable::now('Asia/Manila')->subDays($eventDaysAgo);
            $this->prepare($event, $winnerKey);
            $tokens = $this->guests($event, $this->ownerOf($winnerKey), TicketType::GUEST_LIST, 0, $this->names(mt_rand(6, 12)));
            $this->happened($event, $this->ownerOf($winnerKey), daysAgo: $eventDaysAgo, hours: mt_rand(4, 7),
                scans: array_slice($tokens, 0, (int) ceil(count($tokens) * mt_rand(70, 100) / 100)),
                walkIns: $this->names(mt_rand(0, 2)), headcount: mt_rand(0, 4));
            $date = now('Asia/Manila')->subDays($eventDaysAgo)->toDateString();
            DB::table('inquiries')->where('id', DB::table('quotations')->where('id', $quotation)->value('inquiry_id'))->update(['requested_date' => $date]);

            if (mt_rand(1, 100) <= 40) {
                $this->as($this->u($this->ownerOf($winnerKey)), fn () => app(ReportSharing::class)->share($event->refresh(), $this->u($this->ownerOf($winnerKey)), 'Thank you for choosing us!', false));
            }
            if (mt_rand(1, 100) <= 75) {
                $rating = $this->pick([5, 5, 5, 5, 4, 4, 4, 3, 5, 2]);
                $review = $this->as($client, fn () => app(ReviewManager::class)->create($client, $event->refresh(), $rating, $this->pick(self::REVIEWS[$rating])));
                if (mt_rand(1, 100) <= 50) {
                    $this->as($this->u($this->ownerOf($winnerKey)), fn () => app(ReviewManager::class)->reply($winner, $this->member($winner, $this->ownerOf($winnerKey)), $review['id'],
                        $rating >= 4 ? "Thank you, {$client->first_name}! It was a pleasure working with your family." : "Thank you for the honest feedback, {$client->first_name}. We have talked to our team about it."));
                }
                DB::table('reviews')->where('id', $review['id'])->update(['created_at' => now()->subDays(max(1, $eventDaysAgo - mt_rand(1, 6)))]);
            }
        }
    }

    /** Inquiries that went nowhere: declined, out-bid, cancelled, still open. */
    private function pastInquiries(): void
    {
        $types = ['Wedding', 'Birthday Party', 'Debut', 'Christening', 'Corporate Event'];
        foreach (range(0, 13) as $i) {
            $clientKey = $this->crowd[($i * 5 + 3) % count($this->crowd)];
            $orgKeys = $this->sample(['dream', 'grand', 'matina', 'calinan', 'bajada', 'lanang'], mt_rand(1, 2));
            $orgs = array_map(fn ($k) => $this->orgs[$k], $orgKeys);
            $daysAgo = mt_rand(20, 330);
            $inquiry = $this->inquiry($clientKey, $this->pick($types), mt_rand(3, 25) * 10, $orgs, $daysAgo);
            $model = fn () => Inquiry::query()->findOrFail($inquiry);
            match ($i % 3) {
                // The organizer couldn't take it.
                0 => $this->as($this->u($this->ownerOf($orgKeys[0])), fn () => app(OrganizerInquiries::class)->decline($orgs[0], $model(),
                    $this->pick(['We are fully booked on that date.', 'That is outside our service area, sorry!', 'Our team is on another event that weekend.']))),
                // Quoted, the client said no.
                1 => (function () use ($orgs, $orgKeys, $inquiry, $clientKey) {
                    $q = $this->quote($orgs[0], $this->ownerOf($orgKeys[0]), $inquiry, 'Standard Package', mt_rand(30, 200) * 1000);
                    $this->as($this->u($clientKey), fn () => app(ClientQuotations::class)->decline(Quotation::query()->findOrFail($q), 'We found a package closer to our budget.'));
                })(),
                // The client cancelled.
                2 => $this->as($this->u($clientKey), fn () => app(InquiryManager::class)->cancel($model(), 'We postponed the event.')),
            };
        }
    }

    /** One public ticketed event about every month, all completed, with ticket sales and check-ins. */
    private function pastPublicEvents(): void
    {
        $specs = [
            // organizer, name, type, venue, days ago, [tier, price, capacity]..., about
            ['dream', 'Davao Wedding Fair', 'Other', 'SMX Convention Center, Lanang', 340, [['Visitor', 100, 400], ['Couples Pass (2 people)', 0, 100]], 'Meet Davao\'s wedding suppliers in one place.'],
            ['lanang', 'Battle of the Bands: Davao', 'Concert', 'People\'s Park, Davao City', 250, [['General Admission', 200, 500], ['Front Row', 600, 80]], 'Eight local bands, one trophy.'],
            ['grand', 'Mindanao Business Forum', 'Conference', 'Marco Polo Davao', 300, [['Delegate', 1500, 200]], 'Business leaders on investing in Mindanao.'],
            ['matina', 'Durian & Coffee Festival', 'Other', 'Magsaysay Park, Davao City', 230, [['Tasting Pass', 350, 300], ['Kids', 0, 100]], 'Tastings from Davao farms and roasters.'],
            ['samal', 'Samal Beach Cleanup & Concert', 'Concert', 'Kaputian Beach, Samal', 195, [['Volunteer + Concert', 0, 300], ['Supporter', 300, 100]], 'Clean the beach in the morning, music at sunset.'],
            ['dream', 'Kadayawan Night Market', 'Other', 'Roxas Avenue, Davao City', 160, [['Entrance', 50, 800]], 'Street food, crafts and live performances.'],
            ['lanang', 'Davao Indie Music Night', 'Concert', 'Ateneo de Davao Finster Auditorium', 125, [['Regular', 350, 400], ['VIP', 900, 80]], 'Davao\'s indie scene, one stage.'],
            ['bajada', 'Photography Masterclass', 'Conference', 'Abreeza Mall Activity Center', 95, [['Workshop Seat', 1200, 80]], 'A day of portrait and event photography.'],
            ['calinan', 'Calinan Farm-to-Table Dinner', 'Other', 'Calinan Garden Venue', 70, [['Dinner Seat', 2500, 80]], 'A seven-course dinner from Calinan farms.'],
            ['grand', 'Davao Startup Pitch Night', 'Conference', 'The Pinnacle Hotel', 50, [['Audience', 0, 200], ['Investor Pass', 2000, 80]], 'Twelve startups, five minutes each.'],
        ];
        foreach ($specs as [$orgKey, $name, $type, $venue, $daysAgo, $tiers, $about]) {
            $owner = $this->ownerOf($orgKey);
            $capacity = array_sum(array_column($tiers, 2));
            [$event, $made] = $this->publicEvent($this->orgs[$orgKey], $owner, $name, $type, $venue, $capacity, 30, $tiers, $about);
            foreach ($this->sample($this->crowd, mt_rand(8, 16)) as $buyer) {
                $this->buy($buyer, $event, $this->pick($made), mt_rand(1, 4));
            }
            $tokens = DB::table('tickets')->where('event_id', $event->id)->where('status', 'valid')->pluck('id')
                ->map(fn ($id) => app(GuestList::class)->qrToken(Ticket::query()->find($id)))->filter()->values()->all();
            $this->happened($event, $owner, daysAgo: $daysAgo, hours: mt_rand(4, 8),
                scans: array_slice($tokens, 0, (int) ceil(count($tokens) * mt_rand(65, 95) / 100)),
                walkIns: $this->names(mt_rand(0, 3)), headcount: mt_rand(0, 10));
            $this->backdatePayments($event->id, $daysAgo + mt_rand(3, 20));
        }

        // Coming up, on sale now.
        foreach ([
            ['ushers', 'Davao Fun Run 2026', 'Other', 'Crocodile Park, Davao City', 20, [['5K Runner', 450, 300], ['10K Runner', 650, 200]], '5K and 10K routes along Diversion Road. Includes a finisher shirt.'],
            ['matina', 'Christmas Food Bazaar', 'Other', 'SM Lanang Premier Event Center', 45, [['Entrance', 50, 1000]], 'Holiday food, gifts and a cooking demo every hour.'],
        ] as [$orgKey, $name, $type, $venue, $inDays, $tiers, $about]) {
            [$event, $made] = $this->publicEvent($this->orgs[$orgKey], $this->ownerOf($orgKey), $name, $type, $venue, array_sum(array_column($tiers, 2)), $inDays, $tiers, $about);
            foreach ($this->sample($this->crowd, mt_rand(5, 10)) as $buyer) {
                $this->buy($buyer, $event, $this->pick($made), mt_rand(1, 3));
            }
        }
    }

    /** NaSeRy sent organizers most of their ticket share, monthly-ish. */
    private function payouts(): void
    {
        $admin = $this->u('admin');
        foreach ($this->orgs as $key => $org) {
            $owed = $this->as($admin, fn () => app(Earnings::class)->summary($org->id))['owed'] ?? 0;
            if ($owed < 1000) {
                continue;
            }
            $amount = floor($owed * 0.8 / 100) * 100;
            $this->as($admin, fn () => app(Earnings::class)->record($admin, $org, [
                'amount' => $amount, 'method' => $this->pick(['bank_transfer', 'gcash']),
                'reference' => 'DEMO-'.strtoupper(Str::random(8)), 'paid_at' => now('Asia/Manila')->subDays(mt_rand(5, 25))->format('Y-m-d H:i'),
            ]));
        }
    }

    /**
     * Seed shortcut: everything above was created today; give accounts,
     * reviews and notifications dates that match their story.
     */
    private function backdate(): void
    {
        $demo = DB::table('users')->where('email', 'like', '%@'.$this->domain)->pluck('id');
        foreach ($demo as $id) {
            $first = collect([
                DB::table('inquiries')->where('client_user_id', $id)->min('created_at'),
                DB::table('registrations')->where('purchaser_user_id', $id)->min('created_at'),
                DB::table('organization_members')->where('user_id', $id)->min('created_at'),
            ])->filter()->map(fn ($d) => \Carbon\CarbonImmutable::parse($d))->min();
            $at = ($first ?? now()->subDays(mt_rand(5, 200)))->subDays(mt_rand(1, 15));
            DB::table('users')->where('id', $id)->update(['created_at' => $at, 'email_verified_at' => $at, 'updated_at' => $at]);
        }

        // Guest lists and sales of past events: bought before the event, scanned during it.
        DB::statement(<<<'SQL'
            UPDATE registrations r SET created_at = s.first_start - make_interval(days => 2 + (abs(hashtext(r.id::text)) % 25)), updated_at = s.first_start
            FROM (SELECT event_id, min(starts_at) AS first_start FROM event_sessions GROUP BY event_id) s
            WHERE s.event_id = r.event_id AND r.created_at >= ? AND s.first_start < now()
            SQL, [$this->started]);
        // Upcoming events: sold over the last two weeks.
        DB::statement(<<<'SQL'
            UPDATE registrations r SET created_at = now() - make_interval(days => 1 + (abs(hashtext(r.id::text)) % 14), hours => abs(hashtext(r.id::text)) % 12), updated_at = now()
            WHERE r.created_at >= ? AND EXISTS (SELECT 1 FROM event_sessions s WHERE s.event_id = r.event_id AND s.starts_at > now() - interval '2 hours')
            SQL, [$this->started]);
        DB::statement(<<<'SQL'
            UPDATE payments p SET created_at = r.created_at, paid_at = r.created_at + interval '3 minutes', updated_at = r.created_at
            FROM registrations r WHERE r.id = p.registration_id AND p.created_at >= ? AND r.created_at < ?
            SQL, [$this->started, $this->started]);
        DB::statement('UPDATE platform_fee_snapshots f SET created_at = p.paid_at FROM payments p WHERE p.id = f.payment_id AND f.created_at >= ? AND p.created_at < ?', [$this->started, $this->started]);
        DB::statement('UPDATE tickets t SET created_at = r.created_at, issued_at = r.created_at, updated_at = r.created_at FROM registrations r WHERE r.id = t.registration_id AND t.created_at >= ? AND r.created_at < ?', [$this->started, $this->started]);
        DB::statement('UPDATE qr_tickets q SET created_at = t.created_at, issued_at = t.created_at FROM tickets t WHERE t.id = q.ticket_id AND q.created_at >= ? AND t.created_at < ?', [$this->started, $this->started]);
        DB::statement('UPDATE attendance_records SET created_at = checked_in_at WHERE created_at >= ?', [$this->started]);
        DB::statement('UPDATE walk_in_records SET created_at = recorded_at WHERE created_at >= ?', [$this->started]);
        DB::statement('UPDATE organization_payouts SET created_at = paid_at WHERE created_at >= ?', [$this->started]);

        // Preparation: tasks created ~2 weeks before they're due, finished (and reviewed) a day or two before.
        DB::statement(<<<'SQL'
            UPDATE tasks SET created_at = LEAST(now() - interval '1 day', COALESCE(due_at, now()) - make_interval(days => 10 + (abs(hashtext(id::text)) % 10))),
                updated_at = CASE WHEN status IN ('done', 'in_progress', 'blocked') THEN LEAST(now(), COALESCE(due_at, now()) - make_interval(hours => 6 + (abs(hashtext(id::text)) % 40))) ELSE updated_at END,
                reviewed_at = CASE WHEN reviewed_at IS NOT NULL THEN LEAST(now(), COALESCE(due_at, now()) - make_interval(hours => 2 + (abs(hashtext(id::text)) % 20))) END
            WHERE created_at >= ?
            SQL, [$this->started]);
        DB::statement('UPDATE checklists c SET created_at = x.first, updated_at = x.first FROM (SELECT checklist_id, min(created_at) - interval \'1 hour\' AS first FROM tasks GROUP BY checklist_id) x WHERE x.checklist_id = c.id AND c.created_at >= ?', [$this->started]);
        DB::statement('UPDATE task_assignees a SET created_at = t.created_at, assigned_at = t.created_at FROM tasks t WHERE t.id = a.task_id AND a.created_at >= ?', [$this->started]);
        DB::statement(<<<'SQL'
            UPDATE event_members m SET created_at = x.first, updated_at = x.first
            FROM (SELECT c.event_id, min(t.created_at) - interval '2 hours' AS first FROM tasks t JOIN checklists c ON c.id = t.checklist_id GROUP BY c.event_id) x
            WHERE x.event_id = m.event_id AND m.created_at >= ?
            SQL, [$this->started]);
        DB::statement("UPDATE event_activities SET created_at = LEAST(now(), COALESCE(activity_at, now()) - interval '20 days'), updated_at = LEAST(now(), COALESCE(activity_at, now())) WHERE created_at >= ?", [$this->started]);
        DB::statement('UPDATE event_progress p SET updated_at = LEAST(now(), x.last), created_at = x.first FROM (SELECT c.event_id, min(t.created_at) AS first, max(t.updated_at) AS last FROM tasks t JOIN checklists c ON c.id = t.checklist_id GROUP BY c.event_id) x WHERE x.event_id = p.event_id AND p.created_at >= ?', [$this->started]);

        // Audit trail: each entry at its record's date (a few minutes apart), completions when the event ended.
        DB::statement(<<<'SQL'
            UPDATE audit_logs a SET created_at = LEAST(now(), x.at + make_interval(mins => x.n::int))
            FROM (
                SELECT a2.id, row_number() OVER (PARTITION BY a2.entity_id ORDER BY a2.created_at) AS n,
                    CASE WHEN a2.entity_type = 'events' AND a2.action ILIKE '%complet%' THEN (SELECT max(s.ends_at) FROM event_sessions s WHERE s.event_id = a2.entity_id)
                         WHEN a2.entity_type = 'tasks' AND a2.action <> 'task.created' THEN tk.updated_at
                    WHEN a2.entity_type = 'organization_members' AND a2.action IN ('staff.left', 'staff.deactivated') THEN omm.deactivated_at
                    WHEN a2.entity_type = 'organization_members' AND a2.action = 'staff.joined' THEN omm.joined_at
                         ELSE COALESCE(e.created_at, i.created_at, q.created_at, r.created_at, p.created_at, o.created_at, v.created_at, t.created_at, tk.created_at, ch.created_at, ea.created_at, em.created_at, omm.created_at) END AS at
                FROM audit_logs a2
                LEFT JOIN events e ON a2.entity_type = 'events' AND e.id = a2.entity_id
                LEFT JOIN inquiries i ON a2.entity_type = 'inquiries' AND i.id = a2.entity_id
                LEFT JOIN quotations q ON a2.entity_type = 'quotations' AND q.id = a2.entity_id
                LEFT JOIN registrations r ON a2.entity_type = 'registrations' AND r.id = a2.entity_id
                LEFT JOIN payments p ON a2.entity_type = 'payments' AND p.id = a2.entity_id
                LEFT JOIN organizations o ON a2.entity_type = 'organizations' AND o.id = a2.entity_id
                LEFT JOIN reviews v ON a2.entity_type = 'reviews' AND v.id = a2.entity_id
                LEFT JOIN ticket_types t ON a2.entity_type = 'ticket_types' AND t.id = a2.entity_id
                LEFT JOIN tasks tk ON a2.entity_type = 'tasks' AND tk.id = a2.entity_id
                LEFT JOIN checklists ch ON a2.entity_type = 'checklists' AND ch.id = a2.entity_id
                LEFT JOIN event_activities ea ON a2.entity_type = 'event_activities' AND ea.id = a2.entity_id
                LEFT JOIN event_members em ON a2.entity_type = 'event_members' AND em.id = a2.entity_id
                LEFT JOIN organization_members omm ON a2.entity_type = 'organization_members' AND omm.id = a2.entity_id
                WHERE a2.created_at >= ?
            ) x
            WHERE x.id = a.id AND x.at IS NOT NULL
            SQL, [$this->started]);

        // Notifications made during the seed: date them by what they are about; only the last few days stay unread.
        $made = DB::table('notifications')->where('created_at', '>=', $this->started)->pluck('id')->all();
        DB::statement(<<<'SQL'
            UPDATE notifications n SET created_at = LEAST(now(), GREATEST(n.created_at - interval '400 days', COALESCE(
                (SELECT r.created_at + interval '1 hour' FROM reviews r WHERE r.id::text = n.data->>'review_id'),
                (SELECT q.created_at + interval '2 hours' FROM quotations q WHERE q.id::text = n.data->>'quotation_id'),
                (SELECT p.paid_at FROM payments p WHERE p.id::text = n.data->>'payment_id'),
                (SELECT i.created_at + interval '1 hour' FROM inquiries i WHERE i.id::text = n.data->>'inquiry_id'),
                (SELECT min(s.starts_at) FROM event_sessions s WHERE s.event_id::text = n.data->>'event_id'),
                (SELECT m.deactivated_at FROM organization_members m WHERE m.id::text = n.data->>'organization_member_id' AND m.deactivated_at IS NOT NULL),
                (SELECT m.joined_at FROM organization_members m WHERE m.id::text = n.data->>'organization_member_id' AND m.joined_at IS NOT NULL),
                (SELECT o.created_at + interval '1 hour' FROM organizations o WHERE o.id::text = n.data->>'organization_id'),
                n.created_at)))
            WHERE n.created_at >= ?
            SQL, [$this->started]);
        foreach (array_chunk($made, 500) as $ids) {
            DB::table('notifications')->whereIn('id', $ids)->where('created_at', '<', now()->subDays(4))->update(['read_at' => DB::raw('created_at')]);
        }
    }

    private function ownerOf(string $orgKey): string
    {
        return ['dream' => 'maria', 'grand' => 'carlo', 'samal' => 'liza', 'matina' => 'nina', 'lanang' => 'oscar', 'bajada' => 'tess',
            'calinan' => 'luz', 'tagum' => 'ramon', 'ushers' => 'benjo', 'toril' => 'jomar', 'buhangin' => 'rhea', 'quick' => 'cris'][$orgKey];
    }

    // ---- Preparation: event team, checklists, tasks, activity timeline ------------------

    /** Who does what in each organization (role => account key); organizers without staff do everything themselves. */
    private function roles(string $orgKey): array
    {
        return match ($orgKey) {
            'dream' => ['owner' => 'maria', 'coordinator' => 'ken', 'checkin' => 'aira', 'finance' => 'paolo'],
            'grand' => ['owner' => 'carlo', 'coordinator' => 'jessa', 'checkin' => 'mark', 'finance' => 'carlo'],
            default => array_fill_keys(['owner', 'coordinator', 'checkin', 'finance'], $this->ownerOf($orgKey)),
        };
    }

    /**
     * Checklists of tasks (name, priority, days before the event, role) and the
     * event-day program for the activity timeline (time, title, category, visible to client).
     */
    private function preparationPlan(bool $public): array
    {
        $checklists = $public ? [
            'Permits & suppliers' => [
                ['Secure the city permit and barangay clearance', 'urgent', 45, 'owner'],
                ['Confirm food stall vendors and their fees', 'high', 30, 'coordinator'],
                ['Book the stage, sound and lights', 'high', 25, 'coordinator'],
                ['Arrange portalets and waste disposal', 'medium', 14, 'coordinator'],
            ],
            'Tickets & promotion' => [
                ['Open ticket sales and post the event online', 'high', 35, 'owner'],
                ['Send the reminder post to ticket holders', 'medium', 3, 'owner'],
                ['Reconcile ticket sales with payouts', 'medium', 1, 'finance'],
            ],
            'Event day' => [
                ['Brief the ushers and security team', 'high', 1, 'checkin'],
                ['Set up the check-in gates and QR scanners', 'urgent', 0, 'checkin+coordinator'],
            ],
        ] : [
            'Planning & suppliers' => [
                ['Sign the contract and collect the down payment', 'high', 60, 'finance'],
                ['Confirm the venue booking', 'high', 55, 'owner'],
                ['Book sound, lights and styling suppliers', 'high', 45, 'coordinator'],
                ['Food tasting with the client', 'medium', 30, 'owner'],
                ['Finalize the catering menu and headcount', 'high', 21, 'coordinator'],
            ],
            'Guests & program' => [
                ['Prepare the guest list and send invitations', 'medium', 35, 'coordinator'],
                ['Draft the program and send it to the emcee', 'medium', 14, 'owner'],
                ['Print table numbers and place cards', 'low', 7, 'coordinator'],
                ['Brief the ushers and check-in team', 'medium', 3, 'coordinator+checkin'],
            ],
            'Event day' => [
                ['Collect the final payment', 'high', 7, 'finance'],
                ['Final walkthrough with the client', 'medium', 2, 'owner'],
                ['Set up the check-in desk and QR scanner', 'urgent', 0, 'checkin+coordinator'],
            ],
        ];
        $program = $public ? [
            [-3, 'Ingress: stalls and stage setup', 'Logistics', false], [-1, 'Sound check and gate briefing', 'Staff', false],
            [0, 'Gates open, ticket check-in', 'Registration', true], [1, 'Opening program and welcome', 'Planning', true],
            [3, 'Main performances', 'Other', true], [6, 'Closing and egress', 'Logistics', false],
        ] : [
            [-3, 'Ingress: styling, sound and lights setup', 'Logistics', false], [-1, 'Team briefing at the venue', 'Staff', false],
            [0, 'Guest arrival and registration', 'Registration', true], [0.5, 'Ceremony / opening program', 'Planning', true],
            [1.5, 'Dinner is served', 'Catering', true], [3, 'Program, speeches and dances', 'Planning', true], [4.5, 'Send-off and egress', 'Logistics', false],
        ];

        return [$checklists, $program];
    }

    /**
     * Fill an event's preparation. Past events: everything done and verified.
     * Upcoming: done up to now (one waiting for review, one sent back, one overdue), the rest in progress / blocked / to do.
     */
    private function prepare(Event $event, string $orgKey, bool $public = false): void
    {
        $roles = $this->roles($orgKey);
        $org = $this->orgs[$orgKey];
        $owner = $this->u($roles['owner']);
        $start = CarbonImmutable::parse(DB::table('event_sessions')->where('event_id', $event->id)->min('starts_at'))->setTimezone('Asia/Manila');
        if ($this->eventAt !== null) {
            $start = $this->eventAt; // the date the event is moved to later (seed shortcut)
        }
        [$checklists, $program] = $this->preparationPlan($public);

        $this->as($owner, function () use ($event, $org, $owner, $roles, $start, $checklists, $program, $public) {
            $prep = app(PreparationManager::class);
            $team = app(EventTeam::class);
            // Event team: the owner leads; staff get the event permissions their job needs.
            $labels = ['owner' => 'Event lead', 'coordinator' => 'Coordinator', 'checkin' => 'Check-in & ushers', 'finance' => 'Finance'];
            $perms = ['owner' => EventTeam::EVENT_PERMISSIONS, 'coordinator' => ['checklist.manage', 'task.manage', 'attendee.manage'],
                'checkin' => ['checkin.perform', 'attendee.manage'], 'finance' => ['ticket.manage']];
            $members = [];
            foreach ($roles as $role => $userKey) {
                if (isset($members[$userKey])) {
                    continue;
                }
                $members[$userKey] = $team->assign($event->refresh(), $this->member($org, $userKey), $labels[$role], $perms[$role], $owner->id)->id;
            }

            $all = [];
            foreach ($checklists as $name => $tasks) {
                $checklist = $prep->createChecklist($event->refresh(), ['name' => $name], $owner->id);
                foreach ($tasks as [$taskName, $priority, $daysBefore, $role]) {
                    $due = $start->subDays($daysBefore)->setTime($daysBefore === 0 ? max(8, $start->hour - 2) : 17, 0);
                    $task = $prep->createTask($event->refresh(), $checklist, ['name' => $taskName, 'priority' => $priority, 'due_at' => $due->format('Y-m-d H:i')], $owner->id);
                    // "checkin+coordinator": a task shared by several people.
                    $team->assignTask($event->refresh(), $task, array_values(array_unique(array_map(fn ($r) => $members[$roles[$r]], explode('+', $role)))));
                    $all[] = [$task, $due];
                }
            }

            // Statuses, from the furthest-due task to the event day.
            usort($all, fn ($a, $b) => $a[1] <=> $b[1]);
            $past = array_values(array_filter($all, fn ($t) => $t[1]->isPast()));
            $future = array_values(array_filter($all, fn ($t) => ! $t[1]->isPast()));
            // Past, live or about to start (within 2 days): everything due is done and checked.
            $isPastEvent = $start->lt(now()->addDays(2));
            foreach ($past as $i => [$task, $due]) {
                $left = count($past) - 1 - $i; // 0 = most recently due
                $status = match (true) {
                    $isPastEvent => 'verified',
                    $left === 0 && count($past) >= 4 => 'overdue',
                    $left === 1 && count($past) >= 4 => 'review',
                    $left === 2 && count($past) >= 5 => 'changes',
                    default => 'verified',
                };
                $this->taskStatus($event, $task, $status, $owner->id);
            }
            foreach ($future as $i => [$task]) {
                $this->taskStatus($event, $task, match (true) { $i === 0 => 'in_progress', $i === 1 && count($future) >= 4 => 'blocked', default => 'pending' }, $owner->id);
            }

            // Activity timeline: milestones so far plus the event-day program.
            $acts = app(EventActivities::class);
            $milestones = $public
                ? [[40, 'Event announced and tickets on sale', 'Marketing', 'Milestone', true]]
                : [[60, 'Contract signed and down payment received', 'Planning', 'Milestone', true], [30, 'Food tasting with the client', 'Catering', 'Update', true]];
            foreach ($milestones as [$daysBefore, $title, $category, $tag, $visible]) {
                $at = $start->subDays($daysBefore)->setTime(10, 0);
                $acts->create($owner, $event->refresh(), ['title' => $title, 'category' => $category, 'tag' => $tag, 'person' => trim("{$owner->first_name} {$owner->last_name}"),
                    'activity_at' => $at->format('Y-m-d H:i'), 'status' => $at->isPast() ? 'completed' : 'pending', 'visible_to_client' => $visible]);
            }
            foreach ($program as [$hours, $title, $category, $visible]) {
                $at = $start->addMinutes((int) round($hours * 60));
                $acts->create($owner, $event->refresh(), ['title' => $title, 'category' => $category, 'tag' => 'Action',
                    'activity_at' => $at->format('Y-m-d H:i'), 'status' => $at->isPast() ? 'completed' : 'pending', 'visible_to_client' => $visible]);
            }
        });
        $this->eventAt = null;
    }

    private function taskStatus(Event $event, Task $task, string $status, string $ownerId): void
    {
        $prep = app(PreparationManager::class);
        match ($status) {
            'verified' => $prep->updateTask($event->refresh(), $task->refresh(), ['status' => 'done', 'completion_note' => 'Done and confirmed with the supplier.'], $ownerId),
            'review' => $prep->updateTask($event->refresh(), $task->refresh(), ['status' => 'done', 'completion_note' => 'Sent the final copy; please check.']),
            'changes' => (function () use ($prep, $event, $task, $ownerId) {
                $prep->updateTask($event->refresh(), $task->refresh(), ['status' => 'done', 'completion_note' => 'First draft attached.']);
                $prep->reviewTask($event->refresh(), $task->refresh(), false, 'Please add the vegetarian option and the final headcount.', $ownerId);
            })(),
            'overdue', 'in_progress' => $prep->updateTask($event->refresh(), $task->refresh(), ['status' => 'in_progress']),
            'blocked' => $prep->updateTask($event->refresh(), $task->refresh(), ['status' => 'blocked', 'description' => 'Waiting for the client to confirm the final guest count.']),
            default => null,
        };
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
    /** $eventName: the client's own name for the event; without one the app shows "{type} for {client}". */
    private function inquiry(string $client, string $type, int $guests, array $orgs, int $daysAgo, ?string $eventName = null): string
    {
        $user = $this->u($client);
        $id = $this->as($user, function () use ($user, $type, $guests, $orgs, $eventName) {
            $m = app(InquiryManager::class);
            $inquiry = $m->createDraft($user, [
                'event_type_id' => $this->typeId($type), 'requested_date' => now()->addMonths(3)->toDateString(), 'guest_count' => $guests,
                'location_text' => 'Davao City', 'services' => [['custom_service_name' => 'Event coordination']],
                'additional_requirements' => $eventName !== null ? "Event: {$eventName}" : null,
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
        ?Organization $loserOrg = null, ?string $loserOwner = null, ?int $loserAmount = null, ?string $eventName = null): string
    {
        $inquiry = $this->inquiry($client, $type, $guests, $orgs, $daysAgo, $eventName);
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
        $quotation = Quotation::query()->findOrFail($quotationId);
        // The event matches what the client asked for: same date, same guest count.
        DB::table('inquiries')->where('id', $quotation->inquiry_id)->update(['requested_date' => $start->toDateString()]);
        $guests = (int) DB::table('inquiries')->where('id', $quotation->inquiry_id)->value('guest_count');
        $typeId = DB::table('inquiries')->where('id', $quotation->inquiry_id)->value('event_type_id');

        return $this->as($this->u($ownerKey), fn () => app(EventManager::class)->convert($org, $this->member($org, $ownerKey), $quotation, [
            'name' => $name, 'event_type_id' => $typeId, 'venue_name' => $venue, 'visibility' => 'private', 'overall_capacity' => $guests ?: $capacityHint * 20,
            'sessions' => [['name' => 'Main program', 'starts_at' => $start->format('Y-m-d\TH:i'), 'ends_at' => $start->copy()->addHours(5)->format('Y-m-d\TH:i')]],
        ]));
    }

    /** @return list<string> QR tokens of the invited guests */
    private function guests(Event $event, string $ownerKey, string $tierName, int $price, array $names): array
    {
        return $this->as($this->u($ownerKey), function () use ($event, $ownerKey, $tierName, $price, $names) {
            // A private event's invitations: the closed guest list the event editor manages.
            $tier = app(TierManager::class)->create($event, ['name' => $tierName, 'price' => $price, 'capacity' => max(50, count($names) + 20), 'status' => 'closed']);
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
        if ($event->inquiry_id) {
            DB::table('inquiries')->where('id', $event->inquiry_id)->update(['requested_date' => $start->copy()->setTimezone('Asia/Manila')->toDateString()]);
        }
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
