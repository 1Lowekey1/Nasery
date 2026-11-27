<?php

namespace App\Console\Commands;

use App\Services\Admin\AccountPurge;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Removes data created by manual/E2E testing on a shared database: every
 * account whose email ends in @nasery.test (the reserved test domain used by
 * factories and E2E scripts) and the organizations they own.
 *
 * The sole-owner guard trigger is disabled ONLY inside this command's
 * transaction (DDL is transactional in PostgreSQL), because by design it
 * forbids removing an organization's last owner. Real accounts are never
 * touched. A backup is always taken first.
 *
 * --domain=demo.nasery.test targets the demo dataset instead (nasery:demo:*).
 * --everyone wipes EVERY account and organization (a clean slate before a
 * demo), keeping reference data: standard event types, services catalog,
 * plans and features, platform settings and fee history.
 *
 * Extend the table list as later phases add tenant data (events, tickets...).
 */
#[Signature('nasery:test-data:purge {--force : Actually delete (default: show what would be removed)} {--domain=nasery.test : Accounts whose e-mail ends in @this domain} {--everyone : Every account and organization (keeps reference data)} {--keep=* : With --everyone: e-mail(s) of accounts to keep, e.g. --keep=you@example.com}')]
#[Description('Delete @nasery.test accounts (or another domain, or everyone) and the organizations they own')]
class PurgeTestData extends Command
{
    public function handle(): int
    {
        $db = DB::connection('system');
        $everyone = (bool) $this->option('everyone');
        $domain = ltrim((string) $this->option('domain'), '@');
        $keep = array_map(fn ($e) => mb_strtolower(trim((string) $e)), (array) $this->option('keep'));
        if ($keep && ! $everyone) {
            $this->error('--keep only works together with --everyone.');

            return self::FAILURE;
        }
        $missing = array_diff($keep, $db->table('public.users')->whereIn(DB::raw('lower(email)'), $keep)->pluck('email')->map(fn ($e) => mb_strtolower($e))->all());
        if ($missing) {
            $this->error('No account with: '.implode(', ', $missing).'. Nothing deleted.');

            return self::FAILURE;
        }
        $users = $everyone
            ? $db->table('public.users')->whereNotIn(DB::raw('lower(email)'), $keep ?: [''])->pluck('id')
            : $db->table('public.users')->where('email', 'like', '%@'.$domain)->pluck('id');
        $orgs = $everyone
            ? $db->table('public.organizations')->pluck('id')
            : $db->table('public.organization_members')->whereIn('user_id', $users)->where('is_owner', true)->pluck('organization_id')->unique()->values();

        $this->line(sprintf('%s: %d, organizations: %d', $everyone ? 'ALL accounts'.($keep ? ' except '.implode(', ', $keep) : '') : "Accounts @{$domain}", $users->count(), $orgs->count()));

        if ($users->isEmpty()) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn('Dry run. Re-run with --force to delete.');

            return self::SUCCESS;
        }

        if ($this->call('nasery:db:backup', ['--reason' => 'pre-test-data-purge']) !== self::SUCCESS) {
            $this->error('Backup failed; nothing deleted.');

            return self::FAILURE;
        }

        // The removal itself is shared with Admin → Settings → Delete an account (AccountPurge).
        $counts = app(AccountPurge::class)->purge($users, $orgs);

        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-28s %d', $table, $count));
        }

        $this->info('Purged.');
        // Their uploaded pictures (older than an hour; the daily prune gets the rest).
        $this->call('nasery:media:prune');

        return self::SUCCESS;
    }
}
