<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a raw SQL migration from database/supabase/ as the login role.
 *
 *   php artisan nasery:db:sql 0028_app_role_column_grants.sql            dry run (rolled back)
 *   php artisan nasery:db:sql 0028_app_role_column_grants.sql --apply    backup first, then apply
 */
#[Signature('nasery:db:sql {file : File name inside database/supabase} {--apply : Commit (default is a rolled-back dry run)}')]
#[Description('Dry-run or apply a raw SQL migration from database/supabase')]
class ApplySql extends Command
{
    public function handle(): int
    {
        $name = basename((string) $this->argument('file'));
        $path = database_path('supabase/'.$name);

        if (! is_file($path) || ! str_ends_with($name, '.sql')) {
            $this->error("No such SQL file: database/supabase/{$name}");

            return self::FAILURE;
        }

        // The files carry their own BEGIN/COMMIT; we control the transaction here.
        $sql = preg_replace('/^\s*(BEGIN|COMMIT)\s*;\s*$/mi', '', (string) file_get_contents($path));
        $connection = DB::connection('system');

        if ($this->option('apply')) {
            if ($this->call('nasery:db:backup', ['--reason' => 'pre-'.pathinfo($name, PATHINFO_FILENAME)]) !== self::SUCCESS) {
                $this->error('Backup failed; nothing applied.');

                return self::FAILURE;
            }
        }

        $connection->beginTransaction();

        try {
            // Domain SQL targets public (the system connection defaults to `laravel`).
            $connection->unprepared('SET LOCAL search_path = public;'.PHP_EOL.$sql);
        } catch (Throwable $e) {
            $connection->rollBack();
            $this->error("{$name} failed: ".$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('apply')) {
            $connection->commit();
            $this->info("Applied {$name}. Run php artisan nasery:doctor next.");
        } else {
            $connection->rollBack();
            $this->info("Dry run OK: {$name} executed cleanly and was rolled back. Re-run with --apply to commit.");
        }

        return self::SUCCESS;
    }
}
