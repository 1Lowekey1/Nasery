<?php

namespace Tests\Feature\Events;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Concerns\InteractsWithOrganizations;
use Tests\TestCase;

/** An event started too early (or in an emergency) can be set back to "not started", with a reason. */
class StopEventTest extends TestCase
{
    use InteractsWithOrganizations;

    public function test_a_started_event_can_be_stopped_with_a_reason(): void
    {
        Mail::fake();
        [$org, $owner] = $this->listedOrganizer();
        $day = now('Asia/Manila')->addDays(5)->format('Y-m-d');
        $base = "/api/v1/organizations/{$org->id}/events";
        $event = $this->actingAs($owner)->spaJson('POST', $base, [
            'name' => 'Early Start', 'visibility' => 'private',
            'sessions' => [['starts_at' => "{$day}T18:00", 'ends_at' => "{$day}T22:00"]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('data.id');

        // Only a live event can be stopped.
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$event}/publish")->assertOk();
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$event}/stop", ['reason' => 'Not live yet'])->assertStatus(409);
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$event}/start")->assertOk()->assertJsonPath('data.status', 'ongoing');

        // A reason is required; with one it goes back to published and is logged.
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$event}/stop")->assertUnprocessable();
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$event}/stop", ['reason' => 'Started by mistake'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->assertSame('Started by mistake', DB::table('audit_logs')->where('entity_id', $event)->where('action', 'event.stopped')->value('reason'));

        // It can be started again when it really begins.
        $this->actingAs($owner)->spaJson('POST', "{$base}/{$event}/start")->assertOk()->assertJsonPath('data.status', 'ongoing');
    }
}
