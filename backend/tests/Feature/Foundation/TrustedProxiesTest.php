<?php

namespace Tests\Feature\Foundation;

use App\Support\Http\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\DefinesTestRoutes;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    use DefinesTestRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiRoute('POST', 'ip', fn (Request $request) => ApiResponse::success(['ip' => $request->ip()]), ['throttle:login']);
    }

    private function viaProxy(string $forwardedFor): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/v1/_test/ip', [], ['X-Forwarded-For' => $forwardedFor]);
    }

    public function test_forwarded_header_is_ignored_by_default(): void
    {
        config(['nasery.trusted_proxies' => '']);

        $this->viaProxy('203.0.113.9')->assertJsonPath('data.ip', '10.0.0.5');
    }

    public function test_forwarded_client_ip_is_used_behind_a_trusted_proxy(): void
    {
        config(['nasery.trusted_proxies' => '*']);

        $this->viaProxy('203.0.113.9')->assertJsonPath('data.ip', '203.0.113.9');
    }

    public function test_spoofed_left_entries_are_not_trusted_with_a_single_proxy(): void
    {
        config(['nasery.trusted_proxies' => '*']);

        // Client-supplied "1.2.3.4" is left of what the real proxy appended.
        $this->viaProxy('1.2.3.4, 203.0.113.9')->assertJsonPath('data.ip', '203.0.113.9');
    }

    public function test_rate_limits_are_per_real_client_behind_a_proxy(): void
    {
        config(['nasery.trusted_proxies' => '*']);
        [$max] = config('nasery.rate_limits.login');

        for ($i = 0; $i < $max; $i++) {
            $this->viaProxy('203.0.113.1')->assertOk();
        }

        $this->viaProxy('203.0.113.1')->assertStatus(429);
        $this->viaProxy('203.0.113.2')->assertOk(); // a different user is unaffected
    }
}
