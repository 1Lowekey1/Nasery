<?php

namespace Tests\Feature\Foundation;

use App\Support\Http\ApiResponse;
use Tests\Concerns\DefinesTestRoutes;
use Tests\TestCase;

class HttpSecurityTest extends TestCase
{
    use DefinesTestRoutes;

    public function test_named_rate_limiter_returns_429_envelope(): void
    {
        $this->apiRoute('POST', 'login-like', fn () => ApiResponse::success(), ['throttle:login']);

        [$max] = config('nasery.rate_limits.login');

        for ($i = 0; $i < $max; $i++) {
            $this->postJson('/api/v1/_test/login-like')->assertOk();
        }

        $this->postJson('/api/v1/_test/login-like')
            ->assertStatus(429)
            ->assertJsonPath('error.code', 'RATE_LIMITED')
            ->assertHeader('Retry-After');
    }

    public function test_csrf_cookie_endpoint_issues_xsrf_token_for_known_origin(): void
    {
        $response = $this->get('/sanctum/csrf-cookie', ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/']);

        $response->assertNoContent();
        $response->assertCookie('XSRF-TOKEN');
        $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000');
        $response->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_unknown_origin_gets_no_cors_grant(): void
    {
        $response = $this->getJson('/api/v1/health', ['Origin' => 'https://evil.example']);

        // With a single allowed origin Laravel always echoes that origin; the
        // browser then refuses the mismatch. What must never happen is the
        // foreign origin (or "*") being granted with credentials.
        $this->assertNotContains($response->headers->get('Access-Control-Allow-Origin'), ['https://evil.example', '*']);
    }

    public function test_signed_out_api_request_is_401_even_without_json_accept(): void
    {
        // No "Accept: application/json" (curl, scanners): still a JSON 401, not a 500 from a redirect to a missing login route.
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('success', false);
    }
}
