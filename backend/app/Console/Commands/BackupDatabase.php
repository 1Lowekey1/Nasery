<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * pg_dump of the NaSeRy schemas (public + laravel), including RLS policies,
 * functions and grants. Run before tests against a shared database or any
 * risky operation. Roles (nasery_app) are cluster-level and not included;
 * 0027 recreates them.
 *
 * Restore (DESTRUCTIVE, replaces the dumped objects):
 *   pg_restore --clean --if-exists --no-owner --single-transaction -d "<conn>" <file>
 */
#[Signature('nasery:db:backup {--reason=manual : Short label stored in the file name}')]
#[Description('Dump the public and laravel schemas to storage/backups')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        $config = config('database.connections.pgsql');
        $directory = (string) config('nasery.backup.directory');
        File::ensureDirectoryExists($directory);

        $reason = preg_replace('/[^a-z0-9\-]+/', '-', strtolower((string) $this->option('reason'))) ?: 'manual';
        $file = $directory.DIRECTORY_SEPARATOR.'nasery-'.now()->format('Ymd-His')."-{$reason}.dump";

        $process = new Process([
            (string) config('nasery.backup.pg_dump_path'),
            '--format=custom',
            '--schema=public',
            '--schema=laravel',
            '--file='.$file,
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--username='.$config['username'],
            '--dbname='.$config['database'],
        ], null, [
            // Credentials via environment, never on the command line or in logs.
            'PGPASSWORD' => (string) $config['password'],
            'PGSSLMODE' => (string) ($config['sslmode'] ?? 'prefer'),
        ], null, 600);

        $process->run();

        if (! $process->isSuccessful()) {
            File::delete($file);
            $this->error('Backup failed: '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        $this->info(sprintf('Backup written: %s (%s KB)', $file, number_format(filesize($file) / 1024, 1)));
        $this->prune($directory);

        return self::SUCCESS;
    }

    private function prune(string $directory): void
    {
        $keep = max(1, (int) config('nasery.backup.keep', 10));
        $files = collect(File::glob($directory.DIRECTORY_SEPARATOR.'nasery-*.dump'))->sort()->values();

        $files->slice(0, max(0, $files->count() - $keep))->each(fn (string $old) => File::delete($old));
    }
}
