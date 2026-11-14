<?php

namespace Tests\Feature\Inquiries;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class ThreadTest extends TestCase
{
    use InteractsWithOrganizations;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    /** $client's inquiry sent directly to both organizers; returns the inquiry id. */
    private function inquiry(User $client, Organization ...$orgs): string
    {
        $id = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(), 'guest_count' => 90, 'location_text' => 'Lanang, Davao City',
        ])->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$id}/submit", ['mode' => 'direct', 'organization_ids' => array_map(fn ($o) => $o->id, $orgs)], $this->key())->assertOk();

        return $id;
    }

    private function threadFor(string $inquiry, Organization $org): string
    {
        return DB::table('inquiry_organizations')->where('inquiry_id', $inquiry)->where('organization_id', $org->id)->value('id');
    }

    private function notified(User $user, string $type): int
    {
        return DB::table('notifications')->where('recipient_user_id', $user->id)->where('type', $type)->count();
    }

    public function test_client_and_each_organizer_talk_privately(): void
    {
        $client = User::factory()->create(['first_name' => 'Ella', 'last_name' => 'Reyes']);
        [$orgA, $ownerA] = $this->listedOrganizer('Alpha Events');
        [$orgB, $ownerB] = $this->listedOrganizer('Beta Events');
        $inquiry = $this->inquiry($client, $orgA, $orgB);
        $threadA = $this->threadFor($inquiry, $orgA);

        // The client has one thread per organizer, both open.
        $list = $this->actingAs($client)->spaJson('GET', "/api/v1/inquiries/{$inquiry}/threads")->assertOk()->json('data');
        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['can_send']);

        // Client → Alpha only; Beta hears nothing.
        $this->actingAs($client)->spaJson('POST', "/api/v1/threads/{$threadA}/messages", ['body' => '  Is the garden venue available?  '])
            ->assertCreated()->assertJsonPath('data.body', 'Is the garden venue available?');
        $this->actingAs($client)->spaJson('POST', "/api/v1/threads/{$threadA}/messages", ['body' => 'Also, do you do catering?'])->assertCreated();
        $this->assertSame(1, $this->notified($ownerA, 'message.organizer')); // grouped, not one per message
        $this->assertSame(0, $this->notified($ownerB, 'message.organizer'));

        // Alpha sees the thread with 2 unread from "Ella R."; opening it marks it read.
        $this->actingAs($ownerA)->spaJson('GET', "/api/v1/organizations/{$orgA->id}/threads")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.unread', 2)->assertJsonPath('data.0.client', 'Ella R.');
        $this->actingAs($ownerA)->spaJson('GET', "/api/v1/threads/{$threadA}")->assertOk()
            ->assertJsonCount(2, 'data.messages')->assertJsonPath('data.messages.0.author', 'Ella R.')->assertJsonPath('data.messages.0.mine', false);
        $this->actingAs($ownerA)->spaJson('GET', "/api/v1/organizations/{$orgA->id}/threads?inquiry={$inquiry}")->assertJsonPath('data.0.unread', 0);

        // Alpha replies; the client is told and sees 1 unread.
        $this->actingAs($ownerA)->spaJson('POST', "/api/v1/threads/{$threadA}/messages", ['body' => 'Yes, and we cater too.'])->assertCreated();
        $this->assertSame(1, $this->notified($client, 'message.client'));
        // Mock defense: alerts say which request the message is about, not only who sent it.
        $this->assertStringStartsWith('About your', (string) DB::table('notifications')->where('recipient_user_id', $client->id)->where('type', 'message.client')->value('body'));

        // Live updates: the change stamp stays put until something happens for this person.
        $before = $this->actingAs($client)->spaJson('GET', '/api/v1/me/changes')->assertOk()->json('data.stamp');
        $this->assertSame($before, $this->actingAs($client)->spaJson('GET', '/api/v1/me/changes')->json('data.stamp'));

        // Clearing: one, then all; cleared notices leave the list and the unread count.
        $first = DB::table('notifications')->where('recipient_user_id', $client->id)->value('id');
        $this->actingAs($client)->spaJson('DELETE', "/api/v1/me/notifications/{$first}")->assertOk();
        $this->actingAs($client)->spaJson('GET', '/api/v1/me/notifications')->assertOk()->assertJsonMissing(['id' => $first]);
        $this->actingAs($client)->spaJson('POST', '/api/v1/me/notifications/clear')->assertOk();
        $this->actingAs($client)->spaJson('GET', '/api/v1/me/notifications/unread-count')->assertJsonPath('data.count', 0);
        $this->actingAs($client)->spaJson('GET', '/api/v1/me/notifications')->assertJsonCount(0, 'data');
        $this->assertNotSame($before, $this->actingAs($client)->spaJson('GET', '/api/v1/me/changes')->json('data.stamp'));
        $mine = collect($this->actingAs($client)->spaJson('GET', "/api/v1/inquiries/{$inquiry}/threads")->json('data'))->firstWhere('id', $threadA);
        $this->assertSame(1, $mine['unread']);
        $this->actingAs($client)->spaJson('GET', "/api/v1/threads/{$threadA}")->assertJsonPath('data.messages.2.author', fn ($a) => str_starts_with($a, $orgA->display_name));

        // Having read it (the thread clears its bell notice), the next reply notifies again.
        $this->assertSame(0, DB::table('notifications')->where('recipient_user_id', $client->id)->where('type', 'message.client')->whereNull('read_at')->count());
        $this->actingAs($ownerA)->spaJson('POST', "/api/v1/threads/{$threadA}/messages", ['body' => 'Shall I send the quotation?'])->assertCreated();
        $this->assertSame(2, $this->notified($client, 'message.client'));
        $this->actingAs($client)->spaJson('POST', "/api/v1/threads/{$threadA}/messages", ['body' => 'Yes please.'])->assertCreated();
        $this->assertSame(2, $this->notified($ownerA, 'message.organizer')); // Alpha had read the earlier ones

        // Nobody else reads it: the other organizer, another client, staff without inquiry duties.
        $this->actingAs($ownerB)->spaJson('GET', "/api/v1/threads/{$threadA}")->assertNotFound();
        $this->actingAs(User::factory()->create())->spaJson('GET', "/api/v1/threads/{$threadA}")->assertNotFound();
        $staff = $this->businessUser();
        $this->addStaff($orgA, $staff);
        $this->actingAs($staff)->spaJson('GET', "/api/v1/threads/{$threadA}")->assertNotFound();
        $this->actingAs($staff)->spaJson('GET', "/api/v1/organizations/{$orgA->id}/threads")->assertForbidden();
        $this->actingAs(User::factory()->create())->spaJson('GET', "/api/v1/inquiries/{$inquiry}/threads")->assertNotFound();

        // Empty and over-long messages are refused.
        $this->actingAs($client)->spaJson('POST', "/api/v1/threads/{$threadA}/messages", ['body' => '   '])->assertUnprocessable();
        $this->actingAs($client)->spaJson('POST', "/api/v1/threads/{$threadA}/messages", ['body' => str_repeat('a', 2001)])->assertUnprocessable();
    }

    public function test_only_the_winners_thread_stays_open_after_a_booking(): void
    {
        $client = User::factory()->create();
        [$orgA, $ownerA] = $this->listedOrganizer('Alpha Events');
        [$orgB, $ownerB] = $this->listedOrganizer('Beta Events');
        $inquiry = $this->inquiry($client, $orgA, $orgB);
        $threadA = $this->threadFor($inquiry, $orgA);
        $threadB = $this->threadFor($inquiry, $orgB);
        $this->actingAs($ownerB)->spaJson('POST', "/api/v1/threads/{$threadB}/messages", ['body' => 'Happy to quote, how many hours?'])->assertCreated();

        $quotation = $this->actingAs($ownerA)->spaJson('POST', "/api/v1/organizations/{$orgA->id}/inquiries/{$inquiry}/quotations",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 50000]], 'submit' => true], $this->key())->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/quotations/{$quotation}/accept", [], $this->key())->assertOk();

        $list = collect($this->actingAs($client)->spaJson('GET', "/api/v1/inquiries/{$inquiry}/threads")->json('data'))->keyBy('id');
        $this->assertTrue($list[$threadA]['can_send']);
        $this->assertFalse($list[$threadB]['can_send']);

        // The losing thread is read-only for both sides; its history stays.
        $this->actingAs($client)->spaJson('POST', "/api/v1/threads/{$threadB}/messages", ['body' => 'Thanks anyway'])->assertStatus(409);
        $this->actingAs($ownerB)->spaJson('POST', "/api/v1/threads/{$threadB}/messages", ['body' => 'Any chance?'])->assertStatus(409);
        $this->actingAs($ownerB)->spaJson('GET', "/api/v1/threads/{$threadB}")->assertOk()->assertJsonCount(1, 'data.messages')->assertJsonPath('data.can_send', false);

        // The winner keeps talking into the event.
        $this->actingAs($ownerA)->spaJson('POST', "/api/v1/threads/{$threadA}/messages", ['body' => 'Thank you! Let us set the ocular visit.'])->assertCreated();
        $this->actingAs($client)->spaJson('POST', "/api/v1/threads/{$threadA}/messages", ['body' => 'Saturday works.'])->assertCreated();
    }
}
