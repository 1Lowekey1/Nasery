<?php

namespace Tests;

use App\Support\Database\DatabaseContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tests run against the configured PostgreSQL database (currently the shared
 * Supabase project). Every test is wrapped in transactions on BOTH
 * connections and rolled back, so nothing persists. Never use
 * RefreshDatabase / DatabaseMigrations here: they would wipe shared data.
 */
abstract class TestCase extends BaseTestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected array $connectionsToTransact = ['pgsql', 'system'];

    protected function setUp(): void
    {
        parent::setUp();

        // Approval tests aren't about paperwork; OrganizationDocumentsTest turns this on.
        config(['nasery.organizer_documents.required' => false]);

        foreach (class_uses_recursive(static::class) as $trait) {
            if (str_ends_with($trait, 'RefreshDatabase') || str_ends_with($trait, 'DatabaseMigrations') || str_ends_with($trait, 'DatabaseTruncation')) {
                throw new RuntimeException('Destructive database test traits are forbidden (shared database).');
            }
        }
    }

    /**
     * Pin the ticket platform fee for this test (rolled back afterwards), the way an admin
     * fee change does it: the open version ends and a new one starts. Keeps fee arithmetic
     * independent of whatever fee the shared database currently has.
     */
    protected function useTicketFee(float $rate): void
    {
        $stamp = now()->subSecond()->format('Y-m-d H:i:s.uP');
        app(DatabaseContext::class)->system('tests.fee', function () use ($rate, $stamp) {
            DB::table('platform_fee_configs')->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $stamp))
                ->update(['effective_to' => $stamp]);
            DB::table('platform_fee_configs')->insert([
                'name' => 'Test ticket fee', 'is_enabled' => true, 'fee_model' => 'percentage', 'percentage_rate' => $rate, 'effective_from' => $stamp,
            ]);
        });
    }
}
