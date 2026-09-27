<?php

namespace Tests\Feature\Events;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

class PreparationTest extends TestCase
{
    use InteractsWithOrganizations;

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function local(string $modifier): string
    {
        return now('Asia/Manila')->modify($modifier)->format('Y-m-d\TH:i');
    }

    private function event(User $owner, Organization $org): string
    {
        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Prep Test Wedding',
            'sessions' => [['starts_at' => $this->local('+20 days 16:00'), 'ends_at' => $this->local('+20 days 22:00')]],
        ], $this->key())->assertCreated()->json('data.id');
    }

    private function base(Organization $org, string $event): string
    {
        return "/api/v1/organizations/{$org->id}/events/{$event}";
    }

    /** An event converted from a quotation $client accepted. */
    private function convertedEvent(User $client, Organization $org, User $owner): string
    {
        $inquiry = $this->actingAs($client)->spaJson('POST', '/api/v1/inquiries', [
            'event_type_id' => DB::table('event_types')->whereNull('organization_id')->where('name', 'Wedding')->value('id'),
            'requested_date' => now()->addMonths(2)->toDateString(), 'guest_count' => 80, 'location_text' => 'Davao City',
        ])->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/inquiries/{$inquiry}/submit", ['mode' => 'direct', 'organization_ids' => [$org->id]], $this->key())->assertOk();
        $quotation = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/inquiries/{$inquiry}/quotations",
            ['items' => [['description' => 'Package', 'quantity' => 1, 'unit_price' => 40000]], 'submit' => true], $this->key())->json('data.id');
        $this->actingAs($client)->spaJson('POST', "/api/v1/quotations/{$quotation}/accept", [], $this->key())->assertOk();

        return $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/quotations/{$quotation}/event", [
            'name' => 'Client Wedding',
            'sessions' => [['starts_at' => $this->local('+60 days 16:00'), 'ends_at' => $this->local('+60 days 22:00')]],
        ], $this->key())->assertCreated()->json('data.id');
    }

    public function test_readiness_follows_the_checklist(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->event($owner, $org);
        $base = $this->base($org, $event);

        $this->actingAs($owner)->spaJson('GET', "{$base}/preparation")->assertOk()->assertJsonPath('data.readiness_percent', null)
            ->assertJsonPath('data.stage', 'Planning')
            ->assertJsonPath('data.stages', ['Planning', 'Confirmed', 'Ready', 'Event day', 'Completed']);

        // Starter checklist: six pending tasks.
        $prep = $this->actingAs($owner)->spaJson('POST', "{$base}/checklists", ['starter' => true])->assertCreated()
            ->assertJsonPath('data.checklists.0.total', 6)
            ->assertJsonPath('data.readiness_percent', 0)->json('data');
        $tasks = collect($prep['checklists'][0]['tasks'])->pluck('id');

        foreach ($tasks->take(3) as $task) {
            $this->actingAs($owner)->spaJson('PATCH', "{$base}/tasks/{$task}", ['status' => 'done'])->assertOk();
        }
        $this->actingAs($owner)->spaJson('GET', "{$base}/preparation")->assertJsonPath('data.readiness_percent', 50);

        // Cancelled tasks don't count; deleted ones neither.
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/tasks/{$tasks[3]}", ['status' => 'cancelled'])->assertJsonPath('data.readiness_percent', 60);
        $this->actingAs($owner)->spaJson('DELETE', "{$base}/tasks/{$tasks[4]}")->assertJsonPath('data.readiness_percent', 75);

        // A custom checklist and task with a local due time.
        $checklist = $this->actingAs($owner)->spaJson('POST', "{$base}/checklists", ['name' => 'Styling'])->assertCreated()->json('data.checklists.1.id');
        $this->actingAs($owner)->spaJson('POST', "{$base}/checklists/{$checklist}/tasks", [
            'name' => 'Order flowers', 'priority' => 'urgent', 'due_at' => $this->local('+10 days 09:00'),
        ])->assertCreated()
            ->assertJsonPath('data.checklists.1.tasks.0.due_local', $this->local('+10 days 09:00'))
            ->assertJsonPath('data.readiness_percent', 60);

        $this->assertDatabaseHas('audit_logs', ['action' => 'task.status_changed', 'entity_id' => $tasks[0]]);
    }

    public function test_stage_moves_with_the_event_and_readiness(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $event = $this->event($owner, $org);
        $base = $this->base($org, $event);
        $task = $this->actingAs($owner)->spaJson('POST', "{$base}/checklists", ['starter' => true])->json('data.checklists.0.tasks.0.id');

        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk()->assertJsonPath('data.stage', 'Confirmed');

        // 100% by manual override → Ready; clearing it goes back to computed.
        $this->actingAs($owner)->spaJson('PUT', "{$base}/readiness", ['readiness_percent' => 100])->assertOk()
            ->assertJsonPath('data.readiness_manual', true)->assertJsonPath('data.stage', 'Ready');
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/tasks/{$task}", ['status' => 'done'])->assertJsonPath('data.readiness_percent', 100); // override holds
        $this->actingAs($owner)->spaJson('PUT', "{$base}/readiness", ['readiness_percent' => null])->assertOk()
            ->assertJsonPath('data.readiness_manual', false)
            ->assertJsonPath('data.readiness_percent', 16.67)
            ->assertJsonPath('data.stage', 'Confirmed');
        $this->actingAs($owner)->spaJson('PUT', "{$base}/readiness", ['readiness_percent' => 140])->assertUnprocessable();

        DB::table('event_sessions')->where('event_id', $event)->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $this->actingAs($owner)->spaJson('POST', "{$base}/start")->assertJsonPath('data.stage', 'Event day');
        $this->actingAs($owner)->spaJson('POST', "{$base}/complete")->assertJsonPath('data.stage', 'Completed');

        // Finished events are read-only.
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/tasks/{$task}", ['status' => 'pending'])->assertConflict();
        // Planning → Confirmed → Ready → Confirmed → Event day → Completed
        $this->assertSame(6, DB::table('audit_logs')->where('entity_id', $event)->where('action', 'event.stage_reached')->count());
    }

    public function test_client_sees_milestones_but_never_internal_tasks(): void
    {
        $client = User::factory()->create();
        [$org, $owner] = $this->listedOrganizer('Milestone Co');
        $event = $this->convertedEvent($client, $org, $owner);
        $base = $this->base($org, $event);
        $this->actingAs($owner)->spaJson('POST', "{$base}/checklists", ['name' => 'Secret staff list'])->assertCreated();
        $this->actingAs($owner)->spaJson('POST', "{$base}/publish")->assertOk();

        $progress = $this->actingAs($client)->spaJson('GET', "/api/v1/events/{$event}/progress")->assertOk()
            ->assertJsonPath('data.stage', 'Confirmed')
            ->json('data');
        $messages = collect($progress['milestones'])->pluck('message');

        // accept_quotation() stamps its audit row with the DB transaction time. A
        // test is one transaction, so that row sorts first here; in production
        // each request is its own transaction and the order is chronological.
        $this->assertEqualsCanonicalizing([
            'You sent your inquiry to organizers',
            "You accepted {$org->display_name}'s quotation",
            "{$org->display_name} set up your event",
            'Stage reached: Planning',
            'Stage reached: Confirmed',
        ], $messages->all());
        $this->assertSame(["{$org->display_name} set up your event", 'Stage reached: Planning', 'Stage reached: Confirmed'], $messages->slice(2)->values()->all());
        $this->assertStringNotContainsString('Secret staff list', json_encode($progress));
        $this->assertStringNotContainsString($owner->first_name, json_encode($progress));

        // The organizer's detailed timeline has everything, with who did it.
        $timeline = $this->actingAs($owner)->spaJson('GET', "{$base}/timeline")->assertOk()->json('data');
        $this->assertContains('Checklist “Secret staff list” added', array_column($timeline, 'message'));
        $this->assertContains('Quotation sent to the client', array_column($timeline, 'message'));
        $this->assertSame(trim($owner->first_name.' '.$owner->last_name), collect($timeline)->firstWhere('action', 'checklist.created')['actor']);

        // Clients can't reach the organizer endpoints, nor other clients' progress.
        // (404: RLS hides the organization from a client before the account check.)
        $this->assertContains($this->actingAs($client)->spaJson('GET', "{$base}/preparation")->status(), [403, 404]);
        $this->actingAs(User::factory()->create())->spaJson('GET', "/api/v1/events/{$event}/progress")->assertNotFound();
    }

    public function test_permissions_and_scoping(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        [$other, $otherOwner] = $this->listedOrganizer();
        $this->addStaff($org, $staff = $this->businessUser());
        $event = $this->event($owner, $org);
        $otherEvent = $this->event($otherOwner, $other);
        $otherTask = $this->actingAs($otherOwner)->spaJson('POST', $this->base($other, $otherEvent).'/checklists', ['starter' => true])
            ->json('data.checklists.0.tasks.0.id');

        // Staff on the event's team without permissions can look but not change (B8: others get 404).
        $this->actingAs($staff)->spaJson('GET', $this->base($org, $event).'/preparation')->assertNotFound();
        $this->assignToEvent($owner, $org, $event, $staff);
        $this->actingAs($staff)->spaJson('GET', $this->base($org, $event).'/preparation')->assertOk();
        $this->actingAs($staff)->spaJson('POST', $this->base($org, $event).'/checklists', ['starter' => true])->assertForbidden();

        // A task of another organization's event is not reachable through mine.
        $this->actingAs($owner)->spaJson('PATCH', $this->base($org, $event)."/tasks/{$otherTask}", ['status' => 'done'])->assertNotFound();
        $this->assertSame('pending', DB::table('tasks')->where('id', $otherTask)->value('status'));

        $this->actingAs($owner)->spaJson('POST', $this->base($org, $event).'/checklists', [])->assertUnprocessable();
    }
}
