<?php

namespace Tests\Feature\Testing;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/**
 * Boundary value analysis (BVA) of the inputs where a wrong limit costs money,
 * capacity or trust. Each limit is tried just below, on, and just above its edge
 * through the real API. Results are also written to
 * storage/framework/testing/bva-results.json for the test report (Chapter 4).
 */
class BoundaryValueTest extends TestCase
{
    use InteractsWithOrganizations;

    /** @var list<array{area: string, field: string, value: string, expected: string, actual: int, pass: bool}> */
    private static array $results = [];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware(ThrottleRequests::class); // many requests per test; limits are tested elsewhere
    }

    public static function tearDownAfterClass(): void
    {
        $path = dirname(__DIR__, 3).'/storage/framework/testing/bva-results.json';
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(self::$results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        parent::tearDownAfterClass();
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    /**
     * @param  callable(mixed): TestResponse  $send
     * @param  list<array{0: mixed, 1: bool, 2?: string}>  $cases  [value, accepted?, label]
     */
    private function boundaries(string $area, string $field, callable $send, array $cases): void
    {
        foreach ($cases as $case) {
            [$value, $ok] = $case;
            $label = $case[2] ?? (is_scalar($value) ? (string) $value : json_encode($value));
            $status = $send($value)->status();
            $pass = $ok ? $status >= 200 && $status < 300 : $status === 422 || $status === 409;
            self::$results[] = ['area' => $area, 'field' => $field, 'value' => $label, 'expected' => $ok ? 'accepted' : 'rejected', 'actual' => $status, 'pass' => $pass];
            $this->assertTrue($pass, "{$area} · {$field} = {$label}: expected ".($ok ? 'accepted' : 'rejected').", got HTTP {$status}");
        }
    }

    /** @return array{0: Organization, 1: User, 2: string} [org, owner, event base URL] */
    private function publicEvent(): array
    {
        [$org, $owner] = $this->listedOrganizer();
        $day = now('Asia/Manila')->addDays(30)->format('Y-m-d');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Boundary Fest', 'visibility' => 'public', 'venue_name' => 'Arena',
            'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T23:00"]],
        ], $this->key())->assertCreated()->json('data.id');

        return [$org, $owner, "/api/v1/organizations/{$org->id}/events/{$event}"];
    }

    public function test_price_list_option_limits(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $url = "/api/v1/organizations/{$org->id}/price-options";
        $post = fn (array $extra) => $this->actingAs($owner)->spaJson('POST', $url, ['name' => 'Package', 'pricing_unit' => 'flat', 'price' => 100, ...$extra]);

        $this->boundaries('Price list', 'price (₱0 – ₱999,999,999.99)', fn ($v) => $post(['price' => $v]), [
            [-0.01, false], [0, true], [0.01, true], [999999999.99, true], [1000000000, false], [1.005, false, '1.005 (3 decimals)'],
        ]);
        $this->boundaries('Price list', 'option name (2 – 120 chars)', fn ($v) => $post(['name' => $v]), [
            ['A', false, '1 char'], ['AB', true, '2 chars'], [str_repeat('a', 120), true, '120 chars'], [str_repeat('a', 121), false, '121 chars'],
        ]);
        $this->boundaries('Price list', 'min/max quantity (1 – 100,000)', fn ($v) => $post(['pricing_unit' => 'per_hour', 'min_quantity' => $v]), [
            [0, false], [1, true], [100000, true], [100001, false],
        ]);
        $this->boundaries('Price list', 'max quantity vs min quantity', fn ($v) => $post(['pricing_unit' => 'per_hour', 'min_quantity' => 3, 'max_quantity' => $v]), [
            [2, false, 'max 2 < min 3'], [3, true, 'max 3 = min 3'], [4, true, 'max 4 > min 3'],
        ]);
    }

    public function test_ticket_tier_limits_and_capacity(): void
    {
        [, $owner, $base] = $this->publicEvent();
        $n = 0;
        $tier = fn (array $extra) => $this->actingAs($owner)->spaJson('POST', "{$base}/ticket-types", ['name' => 'Tier '.(++$n), 'price' => 0, 'capacity' => 10, ...$extra]);

        $this->boundaries('Ticketing', 'tier capacity (1 – 1,000,000)', fn ($v) => $tier(['capacity' => $v]), [
            [0, false], [1, true], [1000000, true], [1000001, false],
        ]);
        $this->boundaries('Ticketing', 'tier price (₱0 – ₱9,999,999.99)', fn ($v) => $tier(['price' => $v]), [
            [-0.01, false], [0, true], [9999999.99, true], [10000000, false],
        ]);
    }

    public function test_sold_out_and_capacity_floor(): void
    {
        [, $owner, $base] = $this->publicEvent();
        $tierId = collect($this->actingAs($owner)->spaJson('POST', "{$base}/ticket-types", ['name' => 'Pair', 'price' => 0, 'capacity' => 2, 'status' => 'on_sale'])
            ->assertCreated()->json('data'))->firstWhere('name', 'Pair')['id'];
        $guest = fn (string $name) => $this->actingAs($owner)->spaJson('POST', "{$base}/guests",
            ['full_name' => $name, 'ticket_type_id' => $tierId, 'admission_type' => 'invited', 'send_email' => false], $this->key());

        $this->boundaries('Ticketing', 'tickets issued vs capacity 2', fn ($v) => $guest($v), [
            ['Ana Cruz', true, '1st of 2'], ['Ben Reyes', true, '2nd of 2 (full)'], ['Cora Lim', false, '3rd of 2 (sold out)'],
        ]);
        $this->boundaries('Ticketing', 'lower capacity below 2 issued', fn ($v) => $this->actingAs($owner)->spaJson('PATCH', "{$base}/ticket-types/{$tierId}", ['capacity' => $v]), [
            [1, false, '1 (< 2 issued)'], [2, true, '2 (= issued)'], [3, true, '3'],
        ]);
    }

    public function test_self_registration_group_size(): void
    {
        [, $owner, $base] = $this->publicEvent();
        $this->actingAs($owner)->spaJson('POST', "{$base}/ticket-types", ['name' => 'Free', 'price' => 0, 'capacity' => 500, 'status' => 'on_sale'])->assertCreated();
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();
        $eventId = Str::afterLast($base, '/');
        $tierId = DB::table('ticket_types')->where('event_id', $eventId)->where('name', 'Free')->value('id');
        $people = fn (int $n) => array_map(fn ($i) => ['full_name' => "Guest {$i}"], range(1, max(1, $n)));

        // Each value from its own buyer: since 0068 the cap counts a buyer's earlier orders too.
        $this->boundaries('Ticketing', 'attendees per registration (1 – 10)', fn ($v) => $this->actingAs(User::factory()->create())->spaJson('POST', "/api/v1/public-events/{$eventId}/register",
            ['ticket_type_id' => $tierId, 'attendees' => $v === 0 ? [] : $people($v)], $this->key()), [
            [0, false], [1, true], [10, true], [11, false],
        ]);
    }

    public function test_walk_in_headcount(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $now = now('Asia/Manila');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Door Night', 'visibility' => 'public', 'venue_name' => 'Hall',
            'sessions' => [['starts_at' => $now->copy()->addMinutes(10)->format('Y-m-d\TH:i'), 'ends_at' => $now->copy()->addHours(4)->format('Y-m-d\TH:i')]],
        ], $this->key())->assertCreated()->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$event}";
        $this->actingAs($owner)->spaJson('POST', "{$base}/ticket-types", ['name' => 'Door', 'price' => 0, 'capacity' => 1000, 'status' => 'on_sale'])->assertCreated();
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();
        Carbon::setTestNow(now()->addMinutes(30)); // the doors are open

        $this->boundaries('Check-in', 'walk-in headcount per tap (1 – 50)', fn ($v) => $this->actingAs($owner)->spaJson('POST', "{$base}/walk-ins/headcount", ['count' => $v], $this->key()), [
            [0, false], [1, true], [50, true], [51, false],
        ]);
        Carbon::setTestNow();
    }

    public function test_inquiry_limits(): void
    {
        $client = User::factory()->create();
        $post = fn (array $data) => $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', $data);

        $this->boundaries('Inquiries', 'guest count (1 – 100,000)', fn ($v) => $post(['guest_count' => $v]), [
            [0, false], [1, true], [100000, true], [100001, false],
        ]);
        $this->boundaries('Inquiries', 'budget (₱0 – ₱9,999,999,999.99)', fn ($v) => $post(['budget_min' => $v]), [
            [-0.01, false], [0, true], [9999999999.99, true], [10000000000, false],
        ]);
        $this->boundaries('Inquiries', 'budget max vs min ₱50,000', fn ($v) => $post(['budget_min' => 50000, 'budget_max' => $v]), [
            [49999.99, false], [50000, true], [50000.01, true],
        ]);
        $last = Carbon::parse(\App\Http\Requests\Inquiries\InquiryRequest::lastDate());
        $today = now('Asia/Manila')->startOfDay();
        $this->boundaries('Inquiries', 'event date (today – Dec 31 in 3 years, PH time)', fn ($v) => $post(['requested_date' => $v]), [
            [$today->copy()->subDay()->toDateString(), false, 'yesterday'], [$today->toDateString(), true, 'today'],
            [$last->toDateString(), true, 'last allowed day'], [$last->copy()->addDay()->toDateString(), false, 'day after'],
        ]);
    }

    /**
     * 01:30 in Manila is still 17:30 of the previous day in UTC (the app clock). The date
     * rule must follow Philippine time, or "yesterday" slips through after midnight.
     */
    public function test_inquiry_date_follows_philippine_time_after_midnight(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-28 01:30', 'Asia/Manila'));
        $client = User::factory()->create();
        $post = fn ($v) => $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', ['requested_date' => $v]);

        $this->boundaries('Inquiries', 'event date at 01:30 PH (17:30 UTC day before)', $post, [
            ['2026-10-27', false, 'Oct 27 (PH yesterday)'], ['2026-10-28', true, 'Oct 28 (PH today)'],
        ]);
        Carbon::setTestNow();
    }

    public function test_profile_and_estimate_limits(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $patch = fn (array $data) => $this->actingAs($owner)->spaJson('PATCH', "/api/v1/organizations/{$org->id}/profile", $data);

        $this->boundaries('Business profile', 'service radius km (1 – 200)', fn ($v) => $patch(['service_radius_km' => $v]), [
            [0, false], [1, true], [200, true], [201, false],
        ]);
        $this->boundaries('Business profile', 'estimate service charge % (0 – 30)', fn ($v) => $patch(['estimate_service_charge_percent' => $v]), [
            [-0.01, false], [0, true], [30, true], [30.01, false],
        ]);
        $this->boundaries('Business profile', 'estimate VAT % (0 – 20)', fn ($v) => $patch(['estimate_tax_percent' => $v]), [
            [-0.01, false], [0, true], [20, true], [20.01, false],
        ]);
        $this->boundaries('Business profile', 'about text (≤ 2,000 chars)', fn ($v) => $patch(['description' => $v]), [
            [str_repeat('a', 2000), true, '2,000 chars'], [str_repeat('a', 2001), false, '2,001 chars'],
        ]);
    }

    public function test_event_limits(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $day = now('Asia/Manila')->addDays(20)->format('Y-m-d');
        $create = fn (array $extra) => $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Edge Event', 'visibility' => 'public', 'sessions' => [['starts_at' => "{$day}T10:00", 'ends_at' => "{$day}T12:00"]], ...$extra,
        ], $this->key());

        $this->boundaries('Events', 'event name (2 – 200 chars)', fn ($v) => $create(['name' => $v]), [
            ['A', false, '1 char'], ['AB', true, '2 chars'], [str_repeat('a', 200), true, '200 chars'], [str_repeat('a', 201), false, '201 chars'],
        ]);
        $this->boundaries('Events', 'overall capacity (1 – 1,000,000)', fn ($v) => $create(['overall_capacity' => $v]), [
            [0, false], [1, true], [1000000, true], [1000001, false],
        ]);
        $this->boundaries('Events', 'session end vs start', fn ($v) => $create(['sessions' => [['starts_at' => "{$day}T10:00", 'ends_at' => "{$day}T{$v}"]]]), [
            ['09:59', false, 'ends 1 min before start'], ['10:00', false, 'ends at start'], ['10:01', true, 'ends 1 min after start'],
        ]);
    }

    public function test_account_limits(): void
    {
        $register = fn (array $extra) => $this->spaJson('POST', '/api/v1/auth/register', [
            'first_name' => 'Ana', 'last_name' => 'Cruz', 'email' => 'bva+'.Str::random(8).'@nasery.test',
            'password' => 'Secret123', 'password_confirmation' => 'Secret123', 'account_type' => 'client', ...$extra,
        ]);
        $logout = function () { auth()->forgetGuards(); $this->flushSession(); };

        $this->boundaries('Accounts', 'password length (8 – 128)', function ($v) use ($register, $logout) {
            $r = $register(['password' => $v, 'password_confirmation' => $v]); $logout();

            return $r;
        }, [
            ['Abcdef1', false, '7 chars'], ['Abcdefg1', true, '8 chars'], ['A1'.str_repeat('b', 126), true, '128 chars'], ['A1'.str_repeat('b', 127), false, '129 chars'],
        ]);
        $this->boundaries('Accounts', 'first name (≤ 100 chars)', function ($v) use ($register, $logout) {
            $r = $register(['first_name' => $v]); $logout();

            return $r;
        }, [
            [str_repeat('a', 100), true, '100 chars'], [str_repeat('a', 101), false, '101 chars'],
        ]);
        $this->boundaries('Accounts', 'PH mobile number', function ($v) use ($register, $logout) {
            $r = $register(['phone' => $v]); $logout();

            return $r;
        }, [
            ['0917 123 456', false, '10 digits'], ['0917 123 4567', true, '11 digits'], ['0917 123 45678', false, '12 digits'],
        ]);
    }
}
