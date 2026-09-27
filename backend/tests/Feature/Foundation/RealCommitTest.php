<?php

namespace Tests\Feature\Foundation;

use App\Enums\AccountType;
use App\Support\Database\DatabaseContext;
use App\Support\Http\ApiResponse;
use App\Support\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\DefinesTestRoutes;
use Tests\TestCase;

/**
 * Every other test runs inside a wrapping transaction, so the request's own
 * transaction is only a savepoint there. This class opts out to exercise the
 * real top-level COMMIT / ROLLBACK, verifying from a separate database
 * session. Rows it creates are tagged and always deleted in tearDown.
 */
class RealCommitTest extends TestCase
{
    use DefinesTestRoutes;

    /** @var list<string> */
    protected array $connectionsToTransact = [];

    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tag = 'realcommit+'.Str::lower(Str::random(12));

        $this->apiRoute('POST', 'signup/{outcome}', function (string $outcome, DatabaseContext $context) {
            $context->system('test.signup', fn () => DB::table('users')->insert([
                'id' => (string) Str::uuid(),
                'email' => "{$this->tag}@nasery.test",
                'password_hash' => Hash::make('x'),
                'first_name' => 'Real',
                'last_name' => 'Commit',
                'account_type' => AccountType::Client->value,
            ]));

            return $outcome === 'ok'
                ? ApiResponse::created(null)
                : ApiResponse::error(ErrorCode::Conflict, 'rejected after writing', 409);
        });
    }

    protected function tearDown(): void
    {
        DB::connection('system')->table('public.users')->where('email', 'like', 'realcommit+%@nasery.test')->delete();

        parent::tearDown();
    }

    private function visibleFromAnotherSession(): bool
    {
        return DB::connection('system')->table('public.users')->where('email', "{$this->tag}@nasery.test")->exists();
    }

    public function test_success_is_committed_and_visible_to_other_sessions(): void
    {
        $this->postJson('/api/v1/_test/signup/ok')->assertCreated();

        $this->assertSame(0, DB::transactionLevel());
        $this->assertTrue($this->visibleFromAnotherSession());
        $this->assertSame(DB::scalar('select session_user'), DB::scalar('select current_user'));
    }

    public function test_error_response_rolls_back_the_top_level_transaction(): void
    {
        $this->postJson('/api/v1/_test/signup/fail')->assertConflict();

        $this->assertSame(0, DB::transactionLevel());
        $this->assertFalse($this->visibleFromAnotherSession());
        $this->assertSame(DB::scalar('select session_user'), DB::scalar('select current_user'));
    }
}
