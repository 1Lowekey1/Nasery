<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\SessionService;
use Tests\Concerns\InteractsWithAuth;
use Tests\TestCase;

class SessionManagementTest extends TestCase
{
    use InteractsWithAuth;

    public function test_lists_only_my_sessions_without_exposing_raw_ids(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();
        $this->fakeOtherSession($me, 'my-laptop-session-id-0000000000000000000');
        $this->fakeOtherSession($other, 'someone-else-session-id-00000000000000');

        $response = $this->actingAs($me)->spaJson('GET', '/api/v1/auth/sessions')->assertOk();

        $ids = collect($response->json('data'))->pluck('id');
        $this->assertContains(SessionService::publicId('my-laptop-session-id-0000000000000000000'), $ids);
        $this->assertNotContains(SessionService::publicId('someone-else-session-id-00000000000000'), $ids);
        $this->assertStringNotContainsString('my-laptop-session-id', $response->getContent());
    }

    public function test_can_sign_out_another_device(): void
    {
        $me = User::factory()->create();
        $this->fakeOtherSession($me, 'my-phone-session-id-00000000000000000000');

        $this->actingAs($me)
            ->spaJson('DELETE', '/api/v1/auth/sessions/'.SessionService::publicId('my-phone-session-id-00000000000000000000'))
            ->assertOk();

        $this->assertFalse($this->sessionExists('my-phone-session-id-00000000000000000000'));
    }

    public function test_cannot_sign_out_someone_elses_session(): void
    {
        $me = User::factory()->create();
        $victim = User::factory()->create();
        $this->fakeOtherSession($victim, 'victim-session-id-000000000000000000000');

        $this->actingAs($me)
            ->spaJson('DELETE', '/api/v1/auth/sessions/'.SessionService::publicId('victim-session-id-000000000000000000000'))
            ->assertNotFound();

        $this->assertTrue($this->sessionExists('victim-session-id-000000000000000000000'));
    }

    public function test_can_sign_out_all_other_devices(): void
    {
        $me = User::factory()->create();
        $this->fakeOtherSession($me, 'device-a-session-id-00000000000000000000');
        $this->fakeOtherSession($me, 'device-b-session-id-00000000000000000000');

        $this->actingAs($me)->spaJson('DELETE', '/api/v1/auth/sessions')
            ->assertOk()->assertJsonPath('data.signed_out', 2);

        $this->assertFalse($this->sessionExists('device-a-session-id-00000000000000000000'));
        $this->assertAuthenticatedAs($me);
    }

    public function test_malformed_session_id_is_not_found(): void
    {
        $this->actingAs(User::factory()->create())->spaJson('DELETE', '/api/v1/auth/sessions/not-a-hash')->assertNotFound();
    }
}
