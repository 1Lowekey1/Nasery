<?php

namespace Tests\Feature\Ticketing;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** 0066: buyers choose a table (or get the next one with room); organizers set tables per ticket type. */
class TableSeatingTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['nasery.mail.never_to' => []]);
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    /** A published public event with a free "Dinner" type: 2 tables of 3 ("Table 1", "Table 2"), and a paid one. */
    private function event(): array
    {
        [$org, $owner] = $this->listedOrganizer();
        $day = now('Asia/Manila')->addDays(30)->format('Y-m-d');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Gala Night', 'visibility' => 'public', 'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T22:00"]],
        ], $this->key())->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$event}/ticket-types";
        $dinner = self::named($this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Dinner', 'price' => 0, 'capacity' => 99, 'status' => 'on_sale',
            'table_label' => ' Table ', 'table_count' => 2, 'table_size' => 3])->assertCreated(), 'Dinner');
        $this->assertSame([6, 'Table'], [$dinner['capacity'], $dinner['table_label']]);
        $dinner = $dinner['id'];
        $vip = self::named($this->actingAs($owner)->spaJson('POST', $base, ['name' => 'VIP', 'price' => 500, 'capacity' => 4, 'status' => 'on_sale',
            'table_label' => 'VIP Table', 'table_count' => 2, 'table_size' => 2])->assertCreated(), 'VIP')['id'];
        $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events/{$event}/publish")->assertOk();
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        return [$org, $owner, $event, $dinner, $vip, $base];
    }

    private static function named(\Illuminate\Testing\TestResponse $res, string $name): array
    {
        return collect($res->json('data'))->firstWhere('name', $name);
    }

    private function buy(User $buyer, string $event, string $tier, int $people, ?int $table): \Illuminate\Testing\TestResponse
    {
        $attendees = array_map(fn ($i) => ['full_name' => "Guest {$i}", 'email' => null, 'ticket_type_id' => $tier], range(1, $people));

        return $this->actingAs($buyer)->spaJson('POST', "/api/v1/public-events/{$event}/checkout",
            ['attendees' => $attendees, ...($table ? ['tables' => [$tier => $table]] : [])], $this->key());
    }

    private function seats(string $tier): array
    {
        return DB::table('tickets')->where('ticket_type_id', $tier)->whereIn('status', ['valid', 'pending'])->orderBy('created_at')->pluck('seat_label')->all();
    }

    public function test_buyers_choose_a_table_or_get_the_next_one_with_room(): void
    {
        [, , $event, $dinner] = $this->event();
        $tiers = collect($this->getJson("/api/v1/public-events/{$event}")->assertOk()->json('data.tiers'))->keyBy('name');
        $this->assertSame(['label' => 'Table', 'size' => 3], array_intersect_key($tiers['Dinner']['tables'], array_flip(['label', 'size'])));
        $this->assertSame([3, 3], array_column($tiers['Dinner']['tables']['tables'], 'left'));

        // A couple picks Table 2.
        $this->buy(User::factory()->create(), $event, $dinner, 2, 2)->assertCreated();
        $this->assertSame(['Table 2', 'Table 2'], $this->seats($dinner));

        // Two more can't fit at Table 2 (one seat left): asked to choose another, nothing issued.
        $this->buy(User::factory()->create(), $event, $dinner, 2, 2)->assertStatus(409)->assertJsonPath('error.details.scope', 'table');
        $this->assertCount(2, $this->seats($dinner));

        // "Any table": a group of 3 goes to the first table that seats them all (Table 1).
        $this->buy(User::factory()->create(), $event, $dinner, 3, null)->assertCreated();
        $this->assertSame(['Table 2', 'Table 2', 'Table 1', 'Table 1', 'Table 1'], $this->seats($dinner));

        // The seat shows on the buyer's ticket, and the page shows what's left.
        $left = collect($this->getJson("/api/v1/public-events/{$event}")->json('data.tiers'))->firstWhere('name', 'Dinner')['tables']['tables'];
        $this->assertSame([0, 1], array_column($left, 'left'));
    }

    public function test_a_group_too_big_for_one_table_fills_tables_in_order(): void
    {
        [, , $event, $dinner] = $this->event();
        $this->buy(User::factory()->create(), $event, $dinner, 1, 1)->assertCreated();
        $this->buy(User::factory()->create(), $event, $dinner, 4, null)->assertCreated();
        $this->assertSame(['Table 1', 'Table 1', 'Table 1', 'Table 2', 'Table 2'], $this->seats($dinner));
    }

    public function test_an_unpaid_order_holds_its_seats_until_its_hold_runs_out(): void
    {
        [, , $event, , $vip] = $this->event();
        $this->buy(User::factory()->create(), $event, $vip, 2, 1)->assertCreated()->assertJsonPath('data.status', 'awaiting_payment');
        $this->assertSame(['VIP Table 1', 'VIP Table 1'], $this->seats($vip));
        $this->buy(User::factory()->create(), $event, $vip, 1, 1)->assertStatus(409);

        // The hold runs out: the seats are free again (and the places, as before).
        DB::table('ticket_holds')->where('ticket_type_id', $vip)->update(['expires_at' => now()->subMinute()]);
        $this->buy(User::factory()->create(), $event, $vip, 1, 1)->assertCreated();
    }

    public function test_organizers_cant_remove_occupied_tables_and_can_switch_tables_off(): void
    {
        [, $owner, $event, $dinner, , $base] = $this->event();
        $this->buy(User::factory()->create(), $event, $dinner, 2, 2)->assertCreated();

        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$dinner}", ['table_count' => 1])->assertUnprocessable()->assertJsonPath('error.details.fields.table_count.0', 'Table 2 already has guests, so keep at least 2 tables.');
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$dinner}", ['table_size' => 1])->assertUnprocessable()->assertJsonPath('error.details.fields.table_size.0', 'A table already has 2 guests, so keep at least 2 seats per table.');

        // More tables: the capacity follows.
        $this->assertSame(12, self::named($this->actingAs($owner)->spaJson('PATCH', "{$base}/{$dinner}", ['table_count' => 4])->assertOk(), 'Dinner')['capacity']);

        // Off: first come, first served again; seats already given stay on the tickets.
        $this->assertNull(self::named($this->actingAs($owner)->spaJson('PATCH', "{$base}/{$dinner}", ['table_label' => null, 'table_count' => null, 'table_size' => null, 'capacity' => 12])
            ->assertOk(), 'Dinner')['table_label']);
        $this->assertNull(collect($this->getJson("/api/v1/public-events/{$event}")->json('data.tiers'))->firstWhere('name', 'Dinner')['tables']);
        $this->buy(User::factory()->create(), $event, $dinner, 1, null)->assertCreated();
        $this->assertSame(['Table 2', 'Table 2', null], $this->seats($dinner));
    }

    public function test_rows_give_seats_side_by_side_from_the_front(): void
    {
        [, $owner, $event, , , $base] = $this->event();
        $concert = self::named($this->actingAs($owner)->spaJson('POST', $base, ['name' => 'Concert', 'price' => 0, 'capacity' => 1, 'status' => 'on_sale',
            'seating_mode' => 'rows', 'table_count' => 2, 'table_size' => 5])->assertCreated(), 'Concert');
        $this->assertSame(['rows', 'Row', 10], [$concert['seating_mode'], $concert['table_label'], $concert['capacity']]);
        $concert = $concert['id'];
        $this->flushSession();
        $this->app['auth']->forgetGuards();

        // Any row: front row, two seats together near the middle.
        $this->buy(User::factory()->create(), $event, $concert, 2, null)->assertCreated();
        $this->assertSame(['Row A, Seat 2', 'Row A, Seat 3'], $this->seats($concert));

        $rows = collect($this->getJson("/api/v1/public-events/{$event}")->json('data.tiers'))->firstWhere('name', 'Concert')['tables'];
        $this->assertSame(['rows', 'Row A', 3, [2, 3]], [$rows['mode'], $rows['tables'][0]['name'], $rows['tables'][0]['left'], $rows['tables'][0]['taken']]);

        // Row A has 3 seats left but not side by side: the group still gets Row A (the seats left).
        $this->buy(User::factory()->create(), $event, $concert, 3, 1)->assertCreated();
        $this->assertSame(['Row A, Seat 1', 'Row A, Seat 4', 'Row A, Seat 5'], array_slice($this->seats($concert), 2));
        $this->buy(User::factory()->create(), $event, $concert, 1, 1)->assertStatus(409)->assertJsonPath('error.details.scope', 'table');

        // Row A is full: the next group goes to Row B, side by side.
        $this->buy(User::factory()->create(), $event, $concert, 2, null)->assertCreated();
        $this->assertSame(['Row B, Seat 2', 'Row B, Seat 3'], array_slice($this->seats($concert), 5));

        // Rows in use can't be removed; seats in use can't be cut off.
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$concert}", ['table_count' => 1])->assertUnprocessable()
            ->assertJsonPath('error.details.fields.table_count.0', 'Row B already has guests, so keep at least 2 rows.');
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$concert}", ['table_size' => 4])->assertUnprocessable()
            ->assertJsonPath('error.details.fields.table_size.0', 'Seat 5 in a row is already taken, so keep at least 5 seats per row.');
    }

    public function test_row_names_go_past_z(): void
    {
        $this->assertSame(['A', 'Z', 'AA', 'AB', 'AZ', 'BA'], array_map(fn ($n) => \App\Services\Ticketing\TableSeating::rowName($n), [1, 26, 27, 28, 52, 53]));
    }
}
