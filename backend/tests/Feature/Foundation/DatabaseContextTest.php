<?php

namespace Tests\Feature\Foundation;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DefinesTestRoutes;
use Tests\TestCase;

class DatabaseContextTest extends TestCase
{
    use DefinesTestRoutes;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiRoute('GET', 'whoami', fn () => ApiResponse::success([
            'role' => DB::scalar('select current_user'),
            'uid' => DB::scalar('select auth.uid()::text'),
            'visible_users' => (int) DB::scalar('select count(*) from users'),
        ]));
    }

    public function test_guest_request_runs_as_app_role_without_identity(): void
    {
        $this->getJson('/api/v1/_test/whoami')
            ->assertOk()
            ->assertJsonPath('data.role', config('nasery.db.app_role'))
            ->assertJsonPath('data.uid', null)
            ->assertJsonPath('data.visible_users', 0);
    }

    public function test_authenticated_request_sees_only_itself_through_rls(): void
    {
        $user = User::factory()->create();
        User::factory()->create(); // another tenant's user, must stay invisible

        $this->actingAs($user)
            ->getJson('/api/v1/_test/whoami')
            ->assertOk()
            ->assertJsonPath('data.role', config('nasery.db.app_role'))
            ->assertJsonPath('data.uid', $user->id)
            ->assertJsonPath('data.visible_users', 1);
    }

    public function test_role_and_identity_do_not_leak_after_the_request(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/v1/_test/whoami')->assertOk();

        $this->assertSame(DB::scalar('select session_user'), DB::scalar('select current_user'));
        $this->assertNull(DB::scalar('select auth.uid()::text'));
    }

    public function test_successful_request_commits_and_failed_request_rolls_back(): void
    {
        $user = User::factory()->create();

        $this->apiRoute('POST', 'audit/{outcome}', function (string $outcome, AuditLogger $audit) {
            $audit->record('test.probe', ['test_probe', null], new: ['outcome' => $outcome]);

            return $outcome === 'ok'
                ? ApiResponse::success(null)
                : ApiResponse::error(ErrorCode::Conflict, 'nope', 409);
        });

        $ok = $this->actingAs($user)->postJson('/api/v1/_test/audit/ok')->assertOk();
        $failed = $this->actingAs($user)->postJson('/api/v1/_test/audit/fail')->assertConflict();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'test.probe',
            'actor_user_id' => $user->id,
            'request_id' => $ok->headers->get('X-Request-Id'),
        ]);
        $this->assertDatabaseMissing('audit_logs', ['request_id' => $failed->headers->get('X-Request-Id')]);
    }

    public function test_table_without_grant_maps_to_forbidden(): void
    {
        $this->apiRoute('GET', 'services', fn () => DB::select('select * from services'));

        $this->getJson('/api/v1/_test/services')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_system_context_is_explicit_and_temporary(): void
    {
        $this->apiRoute('GET', 'system', function (DatabaseContext $context) {
            $inside = $context->system('test', fn () => DB::scalar('select current_user'));

            return ApiResponse::success(['inside' => $inside, 'after' => DB::scalar('select current_user')]);
        });

        $response = $this->getJson('/api/v1/_test/system')->assertOk();

        $this->assertNotSame(config('nasery.db.app_role'), $response->json('data.inside'));
        $this->assertSame(config('nasery.db.app_role'), $response->json('data.after'));
    }

    public function test_caught_exception_inside_system_context_does_not_leave_it_elevated(): void
    {
        $this->apiRoute('GET', 'system-throws', function (DatabaseContext $context) {
            try {
                $context->system('test', fn () => throw new \DomainException('handled by caller'));
            } catch (\DomainException) {
            }

            return ApiResponse::success(['after' => DB::scalar('select current_user')]);
        });

        $this->getJson('/api/v1/_test/system-throws')
            ->assertOk()
            ->assertJsonPath('data.after', config('nasery.db.app_role'));
    }

    public function test_streamed_body_runs_under_rls_as_the_request_user(): void
    {
        $user = User::factory()->create();
        User::factory()->create();

        $this->apiRoute('GET', 'export', fn () => response()->stream(function () {
            echo json_encode([
                'role' => DB::scalar('select current_user'),
                'uid' => DB::scalar('select auth.uid()::text'),
                'visible_users' => (int) DB::scalar('select count(*) from users'),
            ]);
        }));

        $body = json_decode($this->actingAs($user)->get('/api/v1/_test/export')->assertOk()->streamedContent(), true);

        $this->assertSame(config('nasery.db.app_role'), $body['role']);
        $this->assertSame($user->id, $body['uid']);
        $this->assertSame(1, $body['visible_users']);
        $this->assertSame(DB::scalar('select session_user'), DB::scalar('select current_user'));
    }

    public function test_as_user_scopes_background_work_and_restores_afterwards(): void
    {
        $user = User::factory()->create();
        $context = app(DatabaseContext::class);

        $seen = $context->asUser($user->id, fn () => [DB::scalar('select current_user'), DB::scalar('select auth.uid()::text')]);

        $this->assertSame([config('nasery.db.app_role'), $user->id], $seen);
        $this->assertSame(DB::scalar('select session_user'), DB::scalar('select current_user'));
        $this->assertFalse($context->isActive());
    }

    public function test_as_user_failure_rolls_back_and_does_not_leak_identity(): void
    {
        $user = User::factory()->create();
        $context = app(DatabaseContext::class);

        try {
            $context->asUser($user->id, function () {
                DB::select('select * from services'); // permission denied -> aborts
            });
            $this->fail('Expected a permission error.');
        } catch (QueryException $e) {
            $this->assertSame('42501', $e->errorInfo[0]);
        }

        $this->assertFalse($context->isActive());
        $this->assertSame(DB::scalar('select session_user'), DB::scalar('select current_user'));
        $this->assertNull(DB::scalar('select auth.uid()::text'));
        $this->assertSame(1, (int) DB::scalar('select 1')); // connection still usable
    }

    public function test_timestamptz_round_trips_through_eloquent(): void
    {
        $user = User::factory()->create();
        $fresh = User::query()->findOrFail($user->id);

        $this->assertInstanceOf(CarbonInterface::class, $fresh->created_at);
        $this->assertInstanceOf(CarbonInterface::class, $fresh->email_verified_at);
        $this->assertEqualsWithDelta($user->email_verified_at->getTimestamp(), $fresh->email_verified_at->getTimestamp(), 1);

        $fresh->update(['first_name' => 'Renamed']);
        $this->assertTrue($fresh->refresh()->updated_at->greaterThanOrEqualTo($fresh->created_at));
    }

    public function test_database_raised_business_rule_maps_to_conflict(): void
    {
        $this->apiRoute('POST', 'raise', fn () => DB::statement("do \$\$ begin raise exception 'rule broken'; end \$\$"));

        $this->postJson('/api/v1/_test/raise')
            ->assertConflict()
            ->assertJsonPath('error.code', 'BUSINESS_RULE_VIOLATION');
    }
}
