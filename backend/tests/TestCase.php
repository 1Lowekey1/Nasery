<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
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

        foreach (class_uses_recursive(static::class) as $trait) {
            if (str_ends_with($trait, 'RefreshDatabase') || str_ends_with($trait, 'DatabaseMigrations') || str_ends_with($trait, 'DatabaseTruncation')) {
                throw new RuntimeException('Destructive database test traits are forbidden (shared database).');
            }
        }
    }
}
