<?php

namespace Tests\Feature\Foundation;

use App\Http\Middleware\EnsureIdempotency;
use App\Models\User;
use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\DefinesTestRoutes;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use DefinesTestRoutes;

    private int $executions = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiRoute('POST', 'charge', function (Request $request) {
            $this->executions++;

            return ApiResponse::created(['charge_id' => (string) Str::uuid(), 'amount' => $request->input('amount')]);
        }, ['idempotent:required']);

        $this->apiRoute('POST', 'optional', fn () => ApiResponse::success(['ran' => ++$this->executions]), ['idempotent']);

        $this->apiRoute('POST', 'crash', fn () => throw new \RuntimeException('down'), ['idempotent']);
    }

    public function test_retry_with_same_key_replays_the_first_response(): void
    {
        $key = (string) Str::uuid();

        $first = $this->postJson('/api/v1/_test/charge', ['amount' => 100], ['Idempotency-Key' => $key])->assertCreated();
        $second = $this->postJson('/api/v1/_test/charge', ['amount' => 100], ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(1, $this->executions);
        $this->assertSame($first->json('data.charge_id'), $second->json('data.charge_id'));
        $second->assertHeader('Idempotency-Replayed', 'true');
    }

    public function test_same_key_with_different_payload_is_rejected(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/_test/charge', ['amount' => 100], ['Idempotency-Key' => $key])->assertCreated();

        $this->postJson('/api/v1/_test/charge', ['amount' => 999], ['Idempotency-Key' => $key])
            ->assertConflict()
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, $this->executions);
    }

    public function test_in_flight_key_is_rejected(): void
    {
        $key = (string) Str::uuid();
        $body = json_encode(['amount' => 5]);

        DB::connection('system')->table('idempotency_keys')->insert([
            'scope' => 'guest', 'key' => $key, 'method' => 'POST', 'path' => 'api/v1/_test/charge',
            'fingerprint' => hash('sha256', 'POST|api/v1/_test/charge|'.$body),
            'status' => 'processing', 'locked_until' => now()->addMinute(), 'expires_at' => now()->addDay(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->call('POST', '/api/v1/_test/charge', [], [], [], $this->transformHeadersToServerVars([
            'Idempotency-Key' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json',
        ]), $body)->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_IN_PROGRESS');

        $this->assertSame(0, $this->executions);
    }

    public function test_required_key_must_be_present(): void
    {
        $this->postJson('/api/v1/_test/charge', ['amount' => 100])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');
    }

    public function test_optional_mode_runs_normally_without_a_key(): void
    {
        $this->postJson('/api/v1/_test/optional')->assertOk();
        $this->postJson('/api/v1/_test/optional')->assertOk();

        $this->assertSame(2, $this->executions);
    }

    public function test_keys_are_scoped_per_user(): void
    {
        $key = (string) Str::uuid();

        $this->actingAs(User::factory()->create())->postJson('/api/v1/_test/charge', ['amount' => 1], ['Idempotency-Key' => $key])->assertCreated();
        $this->actingAs(User::factory()->create())->postJson('/api/v1/_test/charge', ['amount' => 1], ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(2, $this->executions);
    }

    private function seedKey(string $key, string $body, string $status, array $overrides = []): void
    {
        $request = Request::create('/api/v1/_test/charge', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], $body);

        DB::connection('system')->table('idempotency_keys')->insert(array_merge([
            'scope' => 'guest', 'key' => $key, 'method' => 'POST', 'path' => 'api/v1/_test/charge',
            'fingerprint' => EnsureIdempotency::fingerprint($request),
            'status' => $status, 'response_status' => $status === 'completed' ? 201 : null,
            'response_body' => $status === 'completed' ? '{"success":true,"data":{"charge_id":"old"},"message":null}' : null,
            'response_headers' => $status === 'completed' ? '{"Content-Type":"application\/json"}' : null,
            'locked_until' => now()->addMinute(), 'expires_at' => now()->addDay(),
            'created_at' => now()->subMinutes(5), 'updated_at' => now()->subMinutes(5),
        ], $overrides));
    }

    public function test_json_key_order_does_not_change_the_fingerprint(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/_test/charge', ['amount' => 1, 'note' => ['b' => 2, 'a' => 1]], ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/v1/_test/charge', ['note' => ['a' => 1, 'b' => 2], 'amount' => 1], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, $this->executions);
    }

    public function test_list_order_still_matters(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/_test/charge', ['seats' => ['A1', 'A2']], ['Idempotency-Key' => $key])->assertCreated();
        $this->postJson('/api/v1/_test/charge', ['seats' => ['A2', 'A1']], ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_abandoned_request_can_be_retried_with_same_payload(): void
    {
        $key = (string) Str::uuid();
        $this->seedKey($key, '{"amount":7}', 'processing', ['locked_until' => now()->subSecond()]);

        $this->postJson('/api/v1/_test/charge', ['amount' => 7], ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(1, $this->executions);
        $this->assertSame('completed', DB::connection('system')->table('idempotency_keys')->where('key', $key)->value('status'));
    }

    public function test_abandoned_request_cannot_be_hijacked_with_other_payload(): void
    {
        $key = (string) Str::uuid();
        $this->seedKey($key, '{"amount":7}', 'processing', ['locked_until' => now()->subSecond()]);

        $this->postJson('/api/v1/_test/charge', ['amount' => 9999], ['Idempotency-Key' => $key])
            ->assertConflict()->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(0, $this->executions);
    }

    public function test_expired_key_may_be_reused_for_a_new_operation(): void
    {
        $key = (string) Str::uuid();
        $this->seedKey($key, '{"amount":7}', 'completed', ['expires_at' => now()->subMinute()]);

        $response = $this->postJson('/api/v1/_test/charge', ['amount' => 8], ['Idempotency-Key' => $key])->assertCreated();

        $this->assertSame(1, $this->executions);
        $this->assertNotSame('old', $response->json('data.charge_id'));
        $response->assertHeaderMissing('Idempotency-Replayed');
    }

    public function test_completed_key_replays_even_after_lock_window(): void
    {
        $key = (string) Str::uuid();
        $this->seedKey($key, '{"amount":7}', 'completed', ['locked_until' => now()->subHour()]);

        $this->postJson('/api/v1/_test/charge', ['amount' => 7], ['Idempotency-Key' => $key])
            ->assertCreated()
            ->assertJsonPath('data.charge_id', 'old')
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(0, $this->executions);
    }

    public function test_client_errors_are_replayed_not_re_executed(): void
    {
        $this->apiRoute('POST', 'validated', function (Request $request) {
            $this->executions++;
            $request->validate(['amount' => ['required', 'integer']]);
        }, ['idempotent']);

        $key = (string) Str::uuid();
        $this->postJson('/api/v1/_test/validated', [], ['Idempotency-Key' => $key])->assertUnprocessable();
        $this->postJson('/api/v1/_test/validated', [], ['Idempotency-Key' => $key])
            ->assertUnprocessable()
            ->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame(1, $this->executions);
    }

    public function test_malformed_key_is_rejected(): void
    {
        $this->postJson('/api/v1/_test/charge', ['amount' => 1], ['Idempotency-Key' => 'short'])
            ->assertStatus(400)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->postJson('/api/v1/_test/charge', ['amount' => 1], ['Idempotency-Key' => 'has spaces in it'])
            ->assertStatus(400);

        $this->assertSame(0, $this->executions);
    }

    public function test_streamed_responses_are_not_cached_for_replay(): void
    {
        $this->apiRoute('POST', 'stream', fn () => response()->stream(fn () => print ('chunk')), ['idempotent']);

        $key = (string) Str::uuid();
        $this->post('/api/v1/_test/stream', [], ['Idempotency-Key' => $key, 'Accept' => 'application/json'])->assertOk();

        $this->assertFalse(DB::connection('system')->table('idempotency_keys')->where('key', $key)->exists());
    }

    public function test_server_errors_release_the_key_for_retry(): void
    {
        $key = (string) Str::uuid();

        $this->postJson('/api/v1/_test/crash', [], ['Idempotency-Key' => $key])->assertStatus(500);

        $this->assertFalse(DB::connection('system')->table('idempotency_keys')->where('key', $key)->exists());
    }
}
