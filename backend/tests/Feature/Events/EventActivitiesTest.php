<?php

namespace Tests\Feature\Events;

use App\Models\User;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** 0045: the written event program, merged with NaSeRy's automatic history. */
class EventActivitiesTest extends TestCase
{
    use InteractsWithOrganizations;

    public function test_organizers_write_the_program_and_see_it_with_the_history(): void
    {
        [$org, $owner] = $this->listedOrganizer();
        $day = now('Asia/Manila')->addDays(20)->format('Y-m-d');
        $event = $this->actingAs($owner)->spaJson('POST', "/api/v1/organizations/{$org->id}/events", [
            'name' => 'Program Night', 'sessions' => [['starts_at' => "{$day}T16:00", 'ends_at' => "{$day}T22:00"]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');
        $base = "/api/v1/organizations/{$org->id}/events/{$event}/activities";

        $this->actingAs($owner)->spaJson('POST', $base, ['title' => ''])->assertUnprocessable();
        $id = $this->actingAs($owner)->spaJson('POST', $base, [
            'title' => 'Sound check', 'category' => 'Technical', 'person' => 'Ken', 'activity_at' => "{$day}T15:00", 'status' => 'pending',
        ])->assertCreated()->assertJsonPath('data.source', 'organizer')->json('data.id');
        $this->actingAs($owner)->spaJson('PATCH', "{$base}/{$id}", ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed');

        // The list mixes the written program with the automatic history (read-only).
        $rows = collect($this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/activities")->assertOk()->json('data'));
        $this->assertSame('Sound check', $rows->firstWhere('id', $id)['title']);
        $this->assertTrue($rows->contains(fn ($r) => $r['source'] === 'nasery' && $r['event_id'] === $event && $r['editable'] === false));

        // Someone from another organization can't touch it.
        [, $stranger] = $this->listedOrganizer('Other Co');
        $this->actingAs($stranger)->spaJson('PATCH', "{$base}/{$id}", ['status' => 'pending'])->assertNotFound();

        $this->actingAs($owner)->spaJson('DELETE', "{$base}/{$id}")->assertOk();
        $this->assertFalse(collect($this->actingAs($owner)->spaJson('GET', "/api/v1/organizations/{$org->id}/activities")->json('data'))->contains('id', $id));
        $this->assertDatabaseHas('audit_logs', ['action' => 'event_activity.removed', 'entity_id' => $id]);
    }
}
