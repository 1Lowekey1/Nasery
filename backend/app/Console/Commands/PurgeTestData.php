<?php

namespace App\Console\Commands;

use App\Services\Organizations\OrganizationDocuments;
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

        $counts = $db->transaction(function () use ($db, $users, $orgs) {
            $db->unprepared('SET LOCAL search_path = public; ALTER TABLE organization_members DISABLE TRIGGER trg_protect_sole_owner');

            $inquiries = $db->table('inquiries')->whereIn('client_user_id', $users)->pluck('id');
            // Quotations on test inquiries, and any quotation by a test organization.
            $quotations = $db->table('quotations')->whereIn('inquiry_id', $inquiries)->orWhereIn('organization_id', $orgs)->pluck('id');

            // Events of test organizations, and any event made from a test inquiry.
            $events = $db->table('events')->whereIn('organization_id', $orgs)->orWhereIn('inquiry_id', $inquiries)->pluck('id');

            // Ticketing (B9): registrations on test events, and any made by test users.
            $registrations = $db->table('registrations')->whereIn('event_id', $events)->orWhereIn('purchaser_user_id', $users)->pluck('id');
            $tickets = $db->table('tickets')->whereIn('registration_id', $registrations)->orWhereIn('event_id', $events)->pluck('id');
            $attendees = $db->table('tickets')->whereIn('id', $tickets)->pluck('attendee_id')
                ->merge($db->table('walk_in_records')->whereIn('event_id', $events)->whereNotNull('attendee_id')->pluck('attendee_id'))->unique();

            $eventMembers = $db->table('event_members')->whereIn('event_id', $events)->orWhereIn('organization_id', $orgs)->pluck('id');
            $roles = $db->table('organization_roles')->whereIn('organization_id', $orgs)->pluck('id');

            $payments = $db->table('payments')->whereIn('registration_id', $registrations)->pluck('id');

            $reviewIds = $db->table('reviews')->whereIn('event_id', $events)->orWhereIn('client_user_id', $users)->pluck('id');
            $refundIds = $db->table('payment_refunds')->whereIn('payment_id', $payments)->pluck('id');

            $orgIds = $orgs->map(fn ($id) => (string) $id)->all();
            $aboutTest = fn ($q) => $q->whereIn('recipient_user_id', $users)->orWhereIn(DB::raw("data->>'organization_id'"), $orgIds ?: [''])
                ->orWhereIn(DB::raw("data->>'review_id'"), $reviewIds->map(fn ($id) => (string) $id)->all() ?: ['']);

            $n = [
                // B17: admin access, moderation cases and announcements touching test data.
                'admin_access_requests' => $db->table('admin_access_requests')->whereIn('organization_id', $orgs)->orWhereIn('requested_by', $users)
                    ->orWhereIn('admin_user_id', $users)->orWhereIn('decided_by', $users)->delete(),
                'admin_access_grants' => $db->table('admin_access_grants')->whereIn('organization_id', $orgs)->orWhereIn('admin_user_id', $users)->orWhereIn('granted_by', $users)->delete(),
                'moderation_cases' => $db->table('moderation_cases')->whereIn('opened_by', $users)->orWhereIn('subject_id', $reviewIds)->orWhereIn('subject_id', $refundIds)->delete(),
                'platform_announcements' => $db->table('platform_announcements')->whereIn('created_by', $users)->delete(),
                'platform_settings' => $db->table('platform_settings')->whereIn('updated_by', $users)->update(['updated_by' => null]),
                'platform_fee_configs' => $db->table('platform_fee_configs')->whereIn('created_by', $users)->update(['created_by' => null]),
                // B18: plans of test organizations (their payments first).
                'subscription_payments' => $db->table('payments')->whereIn('subscription_invoice_id', $db->table('subscription_invoices')
                    ->whereIn('subscription_id', $db->table('subscriptions')->whereIn('organization_id', $orgs)->select('id'))->select('id'))->delete(),
                'subscription_invoices' => $db->table('subscription_invoices')->whereIn('subscription_id', $db->table('subscriptions')->whereIn('organization_id', $orgs)->select('id'))->delete(),
                'subscriptions' => $db->table('subscriptions')->whereIn('organization_id', $orgs)->delete(),
                // B13: notifications of test users (and other people's, e.g. admin alerts, about test organizations), and ticket e-mail logs of test events.
                'notification_deliveries' => $db->table('notification_deliveries')->whereIn('notification_id', $db->table('notifications')->where($aboutTest)->select('id'))->delete(),
                'notifications' => $db->table('notifications')->where($aboutTest)->delete(),
                'notification_preferences' => $db->table('notification_preferences')->whereIn('user_id', $users)->delete(),
                'mail_deliveries' => $db->table('laravel.mail_deliveries')->whereIn('event_id', $events)->delete(),
                'event_reports' => $db->table('event_reports')->whereIn('event_id', $events)->delete(),
                'review_edits' => $db->table('review_edits')->whereIn('review_id', $db->table('reviews')->whereIn('event_id', $events)->orWhereIn('client_user_id', $users)->select('id'))->delete(),
                'review_responses' => $db->table('review_responses')->whereIn('review_id', $db->table('reviews')->whereIn('event_id', $events)->orWhereIn('client_user_id', $users)->select('id'))->delete(),
                'review_reports' => $db->table('review_reports')->whereIn('review_id', $db->table('reviews')->whereIn('event_id', $events)->orWhereIn('client_user_id', $users)->select('id'))->orWhereIn('reported_by', $users)->delete(),
                'reviews' => $db->table('reviews')->whereIn('event_id', $events)->orWhereIn('client_user_id', $users)->delete(),
                'payment_webhook_events' => $db->table('payment_webhook_events')->whereIn('payment_id', $payments)->delete(),
                'platform_fee_snapshots' => $db->table('platform_fee_snapshots')->whereIn('payment_id', $payments)->delete(),
                'payment_refunds' => $db->table('payment_refunds')->whereIn('payment_id', $payments)->delete(),
                'payments' => $db->table('payments')->whereIn('id', $payments)->delete(),
                'attendance_records' => $db->table('attendance_records')->whereIn('ticket_id', $tickets)->orWhereIn('event_id', $events)->delete(),
                'walk_in_records' => $db->table('walk_in_records')->whereIn('event_id', $events)->delete(),
                'qr_tickets' => $db->table('qr_tickets')->whereIn('ticket_id', $tickets)->delete(),
                'ticket_transfers' => $db->table('ticket_transfers')->whereIn('ticket_id', $tickets)->delete(),
                'tickets' => $db->table('tickets')->whereIn('id', $tickets)->delete(),
                'attendees' => $db->table('attendees')->whereIn('id', $attendees)->delete(),
                'ticket_holds' => $db->table('ticket_holds')->whereIn('registration_id', $registrations)->delete(),
                'registrations' => $db->table('registrations')->whereIn('id', $registrations)->delete(),
                'ticket_types' => $db->table('ticket_types')->whereIn('event_id', $events)->delete(),
                'task_assignees' => $db->table('task_assignees')->whereIn('event_member_id', $eventMembers)->delete(),
                'event_member_permissions' => $db->table('event_member_permissions')->whereIn('event_member_id', $eventMembers)->delete(),
                'event_members' => $db->table('event_members')->whereIn('id', $eventMembers)->delete(),
                'tasks' => $db->table('tasks')->whereIn('checklist_id', $db->table('checklists')->whereIn('event_id', $events)->select('id'))->delete(),
                'checklists' => $db->table('checklists')->whereIn('event_id', $events)->delete(),
                'event_progress' => $db->table('event_progress')->whereIn('event_id', $events)->delete(),
                // 0045: activities organizers wrote on their events.
                'event_activities' => $db->table('event_activities')->whereIn('event_id', $events)->orWhereIn('organization_id', $orgs)->delete(),
                'event_sessions' => $db->table('event_sessions')->whereIn('event_id', $events)->delete(),
                'events' => $db->table('events')->whereIn('id', $events)->delete(),
                'quotation_status_history' => $db->table('quotation_status_history')->whereIn('quotation_id', $quotations)->delete(),
                'quotation_items' => $db->table('quotation_items')->whereIn('quotation_id', $quotations)->delete(),
                'quotations' => $db->table('quotations')->whereIn('id', $quotations)->delete(),
                // 0043: payouts recorded for those organizations.
                'organization_payouts' => $db->table('organization_payouts')->whereIn('organization_id', $orgs)->delete(),
                'organization_price_options' => $db->table('organization_price_options')->whereIn('organization_id', $orgs)->delete(),
                // Threads (0042) of those inquiry participants, and anything test users wrote or read.
                'inquiry_thread_reads' => $db->table('inquiry_thread_reads')->whereIn('inquiry_organization_id', $db->table('inquiry_organizations')
                    ->whereIn('inquiry_id', $inquiries)->orWhereIn('organization_id', $orgs)->select('id'))->orWhereIn('user_id', $users)->delete(),
                'inquiry_messages' => $db->table('inquiry_messages')->whereIn('inquiry_organization_id', $db->table('inquiry_organizations')
                    ->whereIn('inquiry_id', $inquiries)->orWhereIn('organization_id', $orgs)->select('id'))->orWhereIn('sender_user_id', $users)->delete(),
                'inquiry_organizations' => $db->table('inquiry_organizations')->whereIn('inquiry_id', $inquiries)->orWhereIn('organization_id', $orgs)->delete(),
                'inquiry_services' => $db->table('inquiry_services')->whereIn('inquiry_id', $inquiries)->delete(),
                'booking_cancellations' => $db->table('booking_cancellations')->whereIn('inquiry_id', $inquiries)->orWhereIn('organization_id', $orgs)->delete(),
                'inquiries' => $db->table('inquiries')->whereIn('id', $inquiries)->delete(),
                'organizer_approval_requests' => $db->table('organizer_approval_requests')->whereIn('organization_id', $orgs)->delete(),
                'organization_documents' => $this->purgeDocuments($db, $orgs, $users),
                'ownership_transfers' => $db->table('ownership_transfers')->whereIn('organization_id', $orgs)->delete(),
                // 0054 / 0056: organizers' suggestions, and ones the purged admins decided (a decision must name its admin).
                'event_type_suggestions' => $db->table('event_type_suggestions')->whereIn('organization_id', $orgs)->orWhereIn('suggested_by', $users)->orWhereIn('decided_by', $users)->delete(),
                'service_suggestions' => $db->table('service_suggestions')->whereIn('organization_id', $orgs)->orWhereIn('suggested_by', $users)->orWhereIn('decided_by', $users)->delete(),
                'organization_services' => $db->table('organization_services')->whereIn('organization_id', $orgs)->delete(),
                'event_types' => $db->table('event_types')->whereIn('organization_id', $orgs)->delete(),
                'event_stages' => $db->table('event_stages')->whereIn('organization_id', $orgs)->delete(),
                'moderation_reports' => $db->table('moderation_reports')->whereIn('reported_by', $users)->delete(),
                'organization_profiles' => $db->table('organization_profiles')->whereIn('organization_id', $orgs)->delete(),
                'member_permission_overrides' => $db->table('member_permission_overrides')->whereIn('organization_member_id',
                    $db->table('organization_members')->whereIn('organization_id', $orgs)->orWhereIn('user_id', $users)->select('id'))->delete(),
                'organization_members' => $db->table('organization_members')->whereIn('organization_id', $orgs)->orWhereIn('user_id', $users)->delete(),
                'audit_logs' => $db->table('audit_logs')->whereIn('organization_id', $orgs)->orWhereIn('actor_user_id', $users)->orWhereIn('entity_id', $users)->delete(),
                'role_permissions' => $db->table('role_permissions')->whereIn('organization_role_id', $roles)->delete(),
                'organization_roles' => $db->table('organization_roles')->whereIn('id', $roles)->delete(),
                'organizations' => $db->table('organizations')->whereIn('id', $orgs)->delete(),
            ];

            $db->unprepared('ALTER TABLE organization_members ENABLE TRIGGER trg_protect_sole_owner');

            $n['sessions'] = $db->table('laravel.sessions')->whereIn('user_id', $users)->delete();
            $n['auth_codes'] = $db->table('laravel.auth_codes')->whereIn('user_id', $users)->delete();
            $n['social_accounts'] = $db->table('laravel.social_accounts')->whereIn('user_id', $users)->delete();
            $n['users'] = $db->table('users')->whereIn('id', $users)->delete();

            return $n;
        });

        foreach ($counts as $table => $count) {
            $this->line(sprintf('  %-28s %d', $table, $count));
        }

        $this->info('Purged.');
        // Their uploaded pictures (older than an hour; the daily prune gets the rest).
        $this->call('nasery:media:prune');

        return self::SUCCESS;
    }

    /** 0057: the rows, and the private files they point to. */
    private function purgeDocuments($db, $orgs, $users): int
    {
        $rows = $db->table('organization_documents')->whereIn('organization_id', $orgs)->orWhereIn('uploaded_by', $users);
        $store = app(OrganizationDocuments::class);
        (clone $rows)->pluck('stored_path')->each(fn (string $path) => @unlink($store->absolute($path)));

        return $rows->delete();
    }
}
