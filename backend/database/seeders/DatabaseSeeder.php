<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data (permissions, plans, event types, fee config, settings)
     * is seeded by database/supabase/ SQL (0024). No fake accounts are ever
     * seeded into shared databases.
     */
    public function run(): void
    {
        //
    }
}
