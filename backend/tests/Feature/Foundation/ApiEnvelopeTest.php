<?php

namespace Tests\Feature\Foundation;

use App\Exceptions\ApiException;
use App\Support\Http\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\DefinesTestRoutes;
use Tests\TestCase;

class ApiEnvelopeTest extends TestCase
{
    use DefinesTestRoutes;

    public function test_health_returns_success_envelope_and_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.api_version', 'v1');

        $this->assertTrue(Str::isUuid($response->headers->get('X-Request-Id')));
    }

    public function test_valid_incoming_request_id_is_echoed_and_invalid_one_replaced(): void
    {
        $this->getJson('/api/v1/health', ['X-Request-Id' => 'client-trace-1234'])
            ->assertHeader('X-Request-Id', 'client-trace-1234');

        $replaced = $this->getJson('/api/v1/health', ['X-Request-Id' => "bad id\n"])->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid($replaced));
    }

    public function test_unknown_route_returns_not_found_envelope(): void
    {
        $this->getJson('/api/v1/does-not-exist')
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'error' => ['code' => 'NOT_FOUND', 'message' => 'The requested resource was not found.', 'details' => []],
            ]);
    }

    public function test_validation_errors_use_fields_details(): void
    {
        $this->apiRoute('POST', 'validate', function (Request $request) {
            $request->validate(['email' => ['required', 'email']]);
        });

        $this->postJson('/api/v1/_test/validate', ['email' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['fields' => ['email']]]]);
    }

    public function test_api_exception_controls_code_status_and_details(): void
    {
        $this->apiRoute('POST', 'sold-out', function () {
            throw new ApiException(ErrorCode::CapacityExceeded, 'Sold out.', 409, ['ticket_type_id' => 'x']);
        });

        $this->postJson('/api/v1/_test/sold-out')
            ->assertConflict()
            ->assertJsonPath('error.code', 'CAPACITY_EXCEEDED')
            ->assertJsonPath('error.message', 'Sold out.')
            ->assertJsonPath('error.details.ticket_type_id', 'x');
    }

    public function test_unhandled_exception_hides_internals_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        $this->apiRoute('GET', 'boom', fn () => throw new RuntimeException('secret internal detail'));

        $response = $this->getJson('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'SERVER_ERROR');

        $this->assertStringNotContainsString('secret internal detail', $response->getContent());
    }

    public function test_unauthenticated_request_returns_401_envelope(): void
    {
        $this->apiRoute('GET', 'private', fn () => 'ok', ['auth:sanctum']);

        $this->getJson('/api/v1/_test/private')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');
    }
}
