<?php

namespace App\Console\Commands;

use App\Support\Database\DatabaseContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Read-only environment check for local/staging/production: PHP extensions,
 * DB connectivity, schema migrations 0025-0027, and that the RLS context
 * really restricts the app role. Makes no persistent changes (probes run in
 * a rolled-back transaction).
 */
#[Signature('nasery:doctor')]
#[Description('Verify PHP, database schema and RLS security setup')]
class Doctor extends Command
{
    private bool $failed = false;

    public function handle(DatabaseContext $context): int
    {
        $this->line('<options=bold>PHP</>');
        $this->check('PHP 8.5 (project pins 8.5.10)', version_compare(PHP_VERSION, '8.5.0', '>='), PHP_VERSION);
        foreach (['pdo_pgsql', 'openssl', 'mbstring', 'intl', 'sodium'] as $ext) {
            $this->check("ext-{$ext}", extension_loaded($ext));
        }
        $this->check('APP_KEY set', filled(config('app.key')));
        if (app()->isProduction()) {
            $this->check('APP_DEBUG off in production', ! config('app.debug'));
        }

        $this->newLine();
        $this->line('<options=bold>Database</>');

        try {
            $version = DB::scalar('select version()');
            $this->check('domain connection (pgsql)', true, Str::before((string) $version, ' on '));
            $this->check('system connection', DB::connection('system')->scalar('select 1') === 1);
        } catch (Throwable $e) {
            $this->check('database reachable', false, $e->getMessage());

            return self::FAILURE;
        }

        $this->check('0001-0024 schema (users, audit_logs)', $this->exists("select to_regclass('public.audit_logs') is not null"));
        $this->check('0025 applied', $this->exists("select count(*) > 0 from pg_constraint where conname = 'fk_events_creator_member_org'"));
        $this->check('0026 applied (security_invoker views)', $this->exists("select coalesce('security_invoker=true' = any(reloptions), false) from pg_class where relname = 'public_reviews'"));

        $role = (string) config('nasery.db.app_role');
        $roleOk = $this->exists('select count(*) = 1 from pg_roles where rolname = ? and not rolbypassrls and not rolcanlogin', [$role]);
        $this->check("0027 app role '{$role}' (NOLOGIN, no BYPASSRLS)", $roleOk);
        $this->check('0027 laravel schema', $this->exists("select count(*) = 1 from pg_namespace where nspname = 'laravel'"));
        if ($roleOk) {
            $this->check('0028 authority columns not app-writable', $this->exists(
                'select not has_column_privilege(?, \'organizations\', \'approval_status\', \'UPDATE\')
                    and not has_column_privilege(?, \'users\', \'account_type\', \'UPDATE\')
                    and not has_column_privilege(?, \'organization_members\', \'is_primary_owner\', \'UPDATE\')
                    and has_column_privilege(?, \'users\', \'first_name\', \'UPDATE\')',
                [$role, $role, $role, $role],
            ));
        }
        $this->check('0029 marketplace view columns (slug, lat/long)', $this->exists(
            "select count(*) = 3 from information_schema.columns where table_schema = 'public' and table_name = 'public_organization_profiles' and column_name in ('slug', 'latitude', 'longitude')",
        ));
        $this->check('0030 inquiry matching (columns, no self-invites)', $this->exists(
            "select (select count(*) = 3 from information_schema.columns where table_schema = 'public' and table_name = 'inquiries' and column_name in ('latitude', 'longitude', 'match_radius_km'))
                and not has_table_privilege(?, 'inquiry_organizations', 'INSERT')",
            [$role],
        ));
        $this->check('0032 quotation content + status not app-writable', $this->exists(
            "select (select count(*) = 2 from information_schema.columns where table_schema = 'public' and table_name = 'quotations' and column_name in ('package_name', 'proposal'))
                and not has_column_privilege(?, 'quotations', 'status', 'UPDATE')
                and not has_column_privilege(?, 'quotations', 'status', 'INSERT')
                and exists (select 1 from pg_policies where tablename = 'quotation_items' and policyname = 'quotation_items_client_read')",
            [$role, $role],
        ));
        $this->check('0033 price options (table, public view, no anon access)', $this->exists(
            "select to_regclass('public.organization_price_options') is not null
                and to_regclass('public.public_organization_price_options') is not null
                and not has_table_privilege('anon', 'public.organization_price_options', 'SELECT')",
        ));
        $this->check('0034 event core (venue/visibility, client read, links not app-writable)', $this->exists(
            "select (select count(*) = 2 from information_schema.columns where table_schema = 'public' and table_name = 'events' and column_name in ('venue_name', 'visibility'))
                and not has_column_privilege(?, 'events', 'inquiry_id', 'UPDATE')
                and not has_column_privilege(?, 'events', 'inquiry_id', 'INSERT')
                and exists (select 1 from pg_policies where tablename = 'events' and policyname = 'events_client_read')",
            [$role, $role],
        ));
        $this->check('0036 ticket tiers + public event views (no anon access)', $this->exists(
            "select (select count(*) = 2 from information_schema.columns where table_schema = 'public' and table_name = 'ticket_types' and column_name in ('zone_name', 'benefits'))
                and to_regclass('public.public_events') is not null and to_regclass('public.public_ticket_types') is not null
                and not has_table_privilege('anon', 'public.public_events', 'SELECT')",
        ));
        $this->check('0040 admin access requests (consent, read-only for the app)', $this->exists(
            "select to_regclass('public.admin_access_requests') is not null
                and has_table_privilege(?, 'public.admin_access_requests', 'SELECT')
                and not has_table_privilege(?, 'public.admin_access_requests', 'INSERT')",
            [$role, $role],
        ));
        $this->check('0041 SaaS billing (incomplete status, plan limits)', $this->exists(
            "select 'incomplete' = any(enum_range(null::subscription_status)::text[])
                and (select count(*) = 9 from plan_entitlements e join features f on f.id = e.feature_id
                     where f.key in ('analytics_history_months', 'custom_event_type_limit', 'price_option_limit'))",
        ));
        $this->check('0042 inquiry threads (read-only for the app, no admin read)', $this->exists(
            "select to_regclass('public.inquiry_messages') is not null and to_regclass('public.inquiry_thread_reads') is not null
                and has_table_privilege(?, 'public.inquiry_messages', 'SELECT')
                and not has_table_privilege(?, 'public.inquiry_messages', 'INSERT')",
            [$role, $role],
        ));
        $this->check('0043 organization payouts (read-only for the app)', $this->exists(
            "select to_regclass('public.organization_payouts') is not null
                and has_table_privilege(?, 'public.organization_payouts', 'SELECT')
                and not has_table_privilege(?, 'public.organization_payouts', 'INSERT')",
            [$role, $role],
        ));
        $this->check('0044 task reviews (completion note, review status)', $this->exists(
            "select count(*) = 5 from information_schema.columns where table_schema = 'public' and table_name = 'tasks'
                and column_name in ('completion_note', 'review_status', 'review_note', 'reviewed_by', 'reviewed_at')",
        ));
        $this->check('0045 event activities (read-only for the app)', $this->exists(
            "select to_regclass('public.event_activities') is not null
                and has_table_privilege(?, 'public.event_activities', 'SELECT')
                and not has_table_privilege(?, 'public.event_activities', 'INSERT')",
            [$role, $role],
        ));
        $this->check('0046 profile details (business profile panel)', $this->exists(
            "select count(*) = 8 from information_schema.columns where table_schema = 'public' and table_name = 'public_organization_profiles'
                and column_name in ('years_experience', 'service_radius_km', 'event_tags', 'specialties', 'website', 'facebook', 'instagram', 'banner_color')",
        ));
        $this->check('0048 price list details (units, quantities, estimate settings)', $this->exists(
            "select count(*) = 3 from information_schema.columns where table_schema = 'public' and table_name = 'public_organization_price_options'
                and column_name in ('unit_label', 'min_quantity', 'max_quantity')",
        ));
        $this->check('0049 activities can be shown to the client', $this->exists(
            "select count(*) = 1 from information_schema.columns where table_schema = 'public' and table_name = 'event_activities' and column_name = 'visible_to_client'",
        ));
        $this->check('laravel migrations table', $this->exists("select to_regclass('laravel.migrations') is not null"));
        $this->check('every public table has RLS enabled', $this->exists(
            "select count(*) = 0 from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind = 'r' and not c.relrowsecurity",
        ));
        $this->check('anon/authenticated have no base-table grants', $this->exists(
            "select count(*) = 0 from information_schema.role_table_grants g join pg_class c on c.relname = g.table_name join pg_namespace n on n.oid = c.relnamespace and n.nspname = g.table_schema where g.table_schema = 'public' and c.relkind = 'r' and g.grantee in ('anon', 'authenticated')",
        ));

        if ($roleOk) {
            $this->newLine();
            $this->line('<options=bold>RLS context probe</>');
            $this->probe($context);
        }

        $this->newLine();
        $this->failed ? $this->error('Some checks failed.') : $this->info('All checks passed.');

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function probe(DatabaseContext $context): void
    {
        $connection = $context->connection();
        $probeUser = (string) Str::uuid();
        $connection->beginTransaction();

        try {
            $context->enter($probeUser);
            $this->check('runs as app role', $connection->scalar('select current_user') === config('nasery.db.app_role'));
            $this->check('auth.uid() is the request user', $connection->scalar('select auth.uid()::text') === $probeUser);
            $this->check('RLS hides other users', (int) $connection->scalar('select count(*) from users') === 0);
            $this->check('reference data readable (permissions)', (int) $connection->scalar('select count(*) from permissions') > 0);
            // 0031: these recursed (42P17) before; every one must be readable.
            $this->check('inquiry/quotation policies free of recursion (0031)', collect(['inquiries', 'inquiry_organizations', 'inquiry_services', 'quotations', 'quotation_status_history', 'event_progress', 'events', 'event_sessions', 'quotation_items', 'tickets', 'registrations', 'attendees'])
                ->every(fn (string $table) => ! $this->denied($connection, "select 1 from {$table} limit 1")));
            $this->check('audit_logs not directly writable', $this->denied($connection, "insert into audit_logs (action, entity_type) values ('doctor', 'probe')"));

            $this->check('app role cannot read ungranted table (services)', $this->denied($connection, 'select 1 from services limit 1'));
            $elevated = $this->allowed($connection, fn () => $context->system('doctor.probe', fn () => $connection->select('select 1 from services limit 1')));
            $this->check('system context can (explicitly) read it', $elevated);
            $this->check('app role restored after system context', $connection->scalar('select current_user') === config('nasery.db.app_role')
                && $this->denied($connection, 'select 1 from services limit 1'));
        } catch (Throwable $e) {
            $this->check('probe', false, $e->getMessage());
        } finally {
            $context->forget(); // rollback below reverts every SET LOCAL
            $connection->rollBack();
        }
    }

    private function denied($connection, string $sql): bool
    {
        $connection->statement('savepoint doctor_probe');

        try {
            $connection->statement($sql);
            $connection->statement('rollback to savepoint doctor_probe');

            return false;
        } catch (Throwable) {
            $connection->statement('rollback to savepoint doctor_probe');

            return true;
        }
    }

    private function allowed($connection, callable $probe): bool
    {
        $connection->statement('savepoint doctor_allowed');

        try {
            $probe();
            $connection->statement('release savepoint doctor_allowed');

            return true;
        } catch (Throwable) {
            $connection->statement('rollback to savepoint doctor_allowed');

            return false;
        }
    }

    private function exists(string $sql, array $bindings = []): bool
    {
        try {
            return (bool) DB::scalar($sql, $bindings);
        } catch (Throwable) {
            return false;
        }
    }

    private function check(string $label, bool $ok, ?string $detail = null): void
    {
        $this->failed = $this->failed || ! $ok;
        $this->line(sprintf('  %s %s%s', $ok ? '<fg=green>✔</>' : '<fg=red>✘</>', $label, $detail ? " <fg=gray>({$detail})</>" : ''));
    }
}
